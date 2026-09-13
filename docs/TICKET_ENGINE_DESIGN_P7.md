# P7 Ticket Engine Design — Paid Order → Entries → Tickets

Scope is strictly: turning a **paid** P5/P6 order into permanent
Entry/Ticket records, safely under concurrency. Draws, winners, and
competition lifecycle automation are explicitly out of scope — see
"P8/P9 boundaries" at the end.

## Domain model

```
Competition ──┐
              │
User ──────┬──┼── Entry ── Ticket, Ticket, Ticket, ...
           │  │
Order ─────┴──┘
  └── OrderItem  (one per competition line — P5)
        └── Entry  (exactly one, created once, on allocation)
              └── Ticket × quantity
```

- **Entry**: one row per `OrderItem`, created once when that order item
  is allocated. Represents "this user's participation in this
  competition, via this order line." Never edited after creation.
- **Ticket**: one row per unit of quantity within an Entry. A ticket
  number is unique **within its competition** (not globally) and is
  never reassigned, edited, or deleted.

Every ticket is traceable to its competition, user, order, and entry —
`tickets.competition_id`/`user_id`/`order_id` are denormalized directly
onto the ticket row (not resolved only through a join to `entries`),
matching the same denormalization P5/P6 already use (e.g.
`order_items.competition_title` snapshotting `competitions.title`) —
justified here by the account-entries listing and the order-detail
"tickets for this order" query both needing to filter tickets directly
without an extra join. There is no orphan-ticket path: every `Ticket`
insert happens inside the same transaction as its parent `Entry` insert
(see "Transaction & locking strategy").

## Why no `EntryStatus`/`TicketStatus` enum

Both were considered and deliberately **not added** — the same
reasoning P6 already used to leave `PaymentStatus` lean (see
`docs/PAYMENTS_DESIGN_P6.md`): nothing in this phase's flow would ever
set a second value.

- An `Entry`/`Ticket` row is only ever written inside a transaction
  that either fully commits (the row exists, fully formed, allocation
  succeeded) or fully rolls back (the row never existed at all — see
  "Failure behavior"). There is no persisted "pending"/"failed" state
  to represent on the row itself.
- Draw-eligibility ("is this ticket still valid, given its order might
  since have been refunded?") is a **P9** concern, and P9 should derive
  it by joining to the parent `Order.status` (only `paid` orders are
  draw-eligible) — exactly the same principle P6 already established
  for `Order`/`Payment` ("`Order.status` is the clean handoff point,"
  not a duplicated flag copied onto a child record). Adding a
  redundant `Voided` status here would create a second source of truth
  that could drift from `Order.status` after a refund.

If a genuine need appears later (P9), add the enum then — it's a
migration, not a design debt, per the same convention `OrderStatus`/
`PaymentStatus` already set.

## Ticket numbering strategy

**Sequential per competition, starting at 1** — `tickets.ticket_number`
is an `unsignedInteger`, unique per `competition_id` (never globally
unique, never the same as `tickets.id`). The next number for a
competition is `COUNT(*) FROM tickets WHERE competition_id = ?` (taken
*inside* that competition's row lock — see below), so a batch of `n`
tickets gets numbers `count+1 .. count+n`. Tickets are never deleted or
renumbered, so `COUNT(*)` and "highest number assigned" never diverge.

`rand()`/`mt_rand()`/`random_int()` are never used to choose a ticket
number — sequential, deterministic allocation is what makes the
capacity check and the number assignment the same safe operation under
one lock. Randomness belongs to the future draw engine (P9), not here.

`unique(competition_id, ticket_number)` is a real database constraint —
the row lock is the primary correctness mechanism (see below), but the
constraint is a hard safety net: if a bug ever let two allocations
race past the lock, the second insert fails loudly (`QueryException`),
it is never silently retried into a possibly-still-wrong state (unlike
`orders.reference`'s retry-on-collision, which is safe to retry because
a fresh random reference has nothing to do with any other row's state).

## Capacity enforcement

`Competition.max_entries` is the authoritative maximum **ticket count**
for that competition, checked as: `(tickets already allocated for this
competition) + (this order item's quantity) <= max_entries`.
`max_entries` is a required (`NOT NULL`) column (see
`docs/SCHEMA_DESIGN_P2.md`/the competitions migration) — every
competition already has a real, finite cap by the time it can be
purchased at all, so this check has no "uncapped" branch to document or
skip. (`max_entries_per_user`, below, is the one genuinely nullable
limit.)

**Critically, this is the *first* place `max_entries` is enforced
anywhere in the codebase.** Neither P4 (visibility) nor P5 (cart/order
creation) check ticket-count capacity — P5 only gates on
`CompetitionStatus::Active` and the simpler `max_entries_per_user`
bounds check at cart-add time (not against a live ticket count). This
means a competition can, in principle, already have more *paid, order*
quantity requested against it than its `max_entries` allows by the time
allocation runs (e.g. two orders for the last few tickets both happened
to get paid). See "What happens when a paid order can't be allocated"
below for how P7 handles that rather than pretending it can't happen.

## Per-user limit enforcement

Same shape: `Competition.max_entries_per_user`, checked as `(tickets
this user already holds in this competition) + (this order item's
quantity) <= max_entries_per_user`, under the same competition row
lock. `null` means uncapped for that competition — documented, not
invented. This re-checks the limit **atomically at allocation time** —
the cart-time check in `CartController` (P5) is a UX nicety that
catches the obvious case early; it is not, and was never intended to
be, the enforcement boundary. A user could plausibly have multiple
paid orders for the same competition (across separate cart sessions),
so the authoritative check has to happen once, atomically, against
everything already allocated — not against cart or order history.

## Transaction & locking strategy

`TicketAllocationService::allocateOrder(Order $order)`:

```
reload the order fresh (with items), inside a transaction:
    lock the ORDER row FOR UPDATE
        — the per-order idempotency/concurrency boundary: a second
          concurrent call for the *same* order (duplicate job
          execution, duplicate webhook-triggered retry, a manual
          re-run) blocks here until the first finishes, then sees
          entries already exist and does nothing.
    if order.status !== Paid: return (nothing to do — see "Order
        eligibility")
    for each order item NOT yet allocated (an Entry with that
        order_item_id doesn't exist yet), sorted by competition_id
        ascending:
            — sorting avoids a classic lock-ordering deadlock: two
              orders that reference the same two competitions in
              opposite line order would otherwise be able to lock
              them in opposite sequence and deadlock each other.
        lock the COMPETITION row FOR UPDATE
        count tickets already allocated for this competition (FOR UPDATE)
        count tickets this user already holds in this competition (FOR UPDATE)
        if max_entries is set and would be exceeded: throw
            CapacityExceededException (whole transaction rolls back)
        if max_entries_per_user is set and would be exceeded: throw
            PerUserLimitExceededException (whole transaction rolls back)
        create the Entry row
        bulk-insert `quantity` Ticket rows (sequential numbers)
commit
```

Everything for one `allocateOrder()` call — every order item — is one
transaction. If any single item can't be allocated, **none** of that
call's items are (requirement #7: no silent partial allocation). Items
already allocated by an *earlier, separate, successful* call are left
untouched (skipped, not re-inserted) — this is what makes calling
`allocateOrder($order)` twice produce the same end state as calling it
once, even if the two calls don't perfectly overlap in which items they
consider.

Locking the competition row (not just checking a count and hoping) is
what actually prevents the race in the spec's own example: "10 tickets
left, user A buys 6, user B buys 6 concurrently" — whichever
transaction acquires the competition's row lock first does its full
count-check-insert under that lock; the second transaction blocks until
the first commits (or rolls back), then re-counts against the
now-current total and correctly rejects. See "Concurrency test" for
the actual verification, not just this description.

**Both `COUNT(*)` queries (capacity and per-user) must themselves be
locking reads (`FOR UPDATE`), not plain `SELECT`s — this is not
optional, and was not obvious until the concurrency test below actually
caught it.** MySQL's default `REPEATABLE READ` isolation level means a
plain `SELECT COUNT(*)` inside a transaction keeps returning results
from that transaction's original consistent snapshot, even while that
same transaction holds an exclusive lock on the competition row — the
row lock only serializes *writers* on the competition row itself, it
does not make an ordinary read of a *different* table (`tickets`)
observe another transaction's later commit. A locking read
(`->lockForUpdate()` on the count query) always reads the latest
committed data regardless of isolation level, which is what actually
makes the count correct under concurrency. Without it, the first
version of this code still never let the ticket count exceed
`max_entries` — but only because the *second* line of defense (the
`unique(competition_id, ticket_number)` database constraint) caught the
resulting duplicate-number insert with a raw
`UniqueConstraintViolationException`, not the intended, catchable
`CapacityExceededException` — meaning the queued job would not have
failed gracefully (see "Queue/job behavior"), it would have retried a
deterministic failure 3 times pointlessly before landing in
`failed_jobs` with a confusing exception type. The concurrency test is
what surfaced this; it is not a hypothetical concern.

## What happens when a paid order can't be allocated

Because nothing upstream strictly enforces `max_entries` (see
"Capacity enforcement"), a paid order's allocation can legitimately
fail the capacity or per-user check. P7's answer:

- The order **stays `paid`** — payment already happened; P7 never
  touches `Order.status`, and it certainly never invents a `cancelled`/
  `failed` transition for the order over a ticket-side problem (P6's
  `PaymentService` already established this same principle for a
  failed *payment* attempt).
- No entries/tickets are created for that order (the whole transaction
  rolled back).
- The allocation job fails **permanently, without retrying** — see
  "Queue/job behavior" — since retrying a capacity conflict changes
  nothing; it lands in Laravel's own `failed_jobs` table for
  operational visibility.
- Resolving it (refund, wait for cancellations/returns to free up
  capacity, manual override) is an **operational/ops decision outside
  P7's scope** — P7's job is to never fabricate tickets that don't fit,
  not to decide what happens next for that customer. This is a known,
  documented limitation, not a silent gap (see the final report).

## Idempotency strategy

Three layers, each independently sufficient, all present:

1. **Order row lock** — the primary mechanism. A second concurrent
   `allocateOrder()` call for the same order simply waits, then finds
   nothing left to do.
2. **Per-item existence check** (`Entry` with this `order_item_id`
   already exists → skip) — makes a *specific* item idempotent even
   across calls that don't fully overlap (e.g. a previous call
   partially completed before a deploy/restart interrupted the queue
   worker — this shouldn't happen given transactions, but costs
   nothing to also guard against it directly).
3. **`unique(entries.order_item_id)`** — the database-level safety net;
   a bug that ever let two allocation attempts insert an `Entry` for
   the same order item fails loudly rather than silently duplicating.

Calling `allocateOrder($order)` 0, 1, or many times (exactly what a
queue can do to a job) always converges to the same end state.

## Queue/job behavior

Allocation runs via a queued job, `AllocateTicketsForOrder`, on the
existing `redis` queue (already running — see P1's `queue` container),
not synchronously inside the payment-verification request. Reasons:

- Keeps the customer-facing `POST /v1/payments/verify` response fast —
  it doesn't need to wait on potentially-contended competition-row
  locks for a popular competition.
- The queue worker already retries transient failures
  (`queue:work --tries=3`, established in P1's `docker-compose.yml`) —
  exactly the "a job can execute 0, 1, or multiple times" guarantee the
  idempotency design above is built to tolerate.

The job's `handle()` calls `TicketAllocationService::allocateOrder()`
and does nothing else. On a `CapacityExceededException`/
`PerUserLimitExceededException` (a business-rule outcome, not a
transient fault), it calls `$this->fail($e)` immediately instead of
letting Laravel's normal retry loop burn through 3 attempts pointlessly
— those exceptions are deterministic; retrying changes nothing. Any
other exception (DB connection blip, deadlock) is left to Laravel's
normal retry behavior. If the job ultimately fails (any reason), it
lands in `failed_jobs` — no custom failure-log table is added (see
"Audit strategy").

## Order/payment handoff

`PaymentService::markPaid()` (P6, unchanged in every way except one
addition) fires an `App\Events\OrderPaid` event — via `DB::afterCommit()`
so it only fires once the `paid` transition has actually, durably
committed — **only on the call that actually performs the transition**
(the existing idempotent-no-op branch, when the payment/order was
already paid, does not re-fire it). `App\Listeners\DispatchTicketAllocation`
listens for `OrderPaid` and dispatches `AllocateTicketsForOrder` — it
is picked up by Laravel's event auto-discovery purely from its
`handle(OrderPaid $event)` type-hint (anything under `app/Listeners` is
auto-discovered), so it is deliberately **not** also registered
manually in `AppServiceProvider` — doing both would fire it twice per
event (caught by `PaymentHandoffTest`, which is exactly why this note
exists).

This is the full extent of the P6 change: one `event()` call inside
`markPaid()`. `PaymentService` knows nothing about entries, tickets, or
allocation — it only knows it fired a domain event. The webhook
controller is untouched; it was already calling into `PaymentService`
exactly like the browser-verification endpoint, so both paths trigger
allocation identically, automatically, with no P7-specific code added
to either.

## Audit strategy

No separate `ticket_allocations`/audit-log table. The `Entry` row
itself **is** the audit record — it already answers which order, which
user, which competition, and when (`created_at`), and its `quantity`
answers how many. `Ticket.allocated_at` (present per the field list
this phase specified, even though it always equals `created_at` today
— there is no reservation-then-confirm phase) makes "when was this
specific ticket allocated" self-describing without requiring a reader
to know that `created_at` doubles as the allocation timestamp.

"Was it retried?" / "did it fail?" are **operational**, not business,
facts — Laravel's own `failed_jobs` table (already part of this app's
schema since P1) is the existing, correct place for them; duplicating
that into a bespoke table would be exactly the "excessive duplicate
data" this phase was told to avoid. The allocation job additionally
logs (not persists) a single line — `ticket.allocation.retried` — when
it succeeds on any attempt after the first, purely for operational
grep-ability; no secrets, no payloads.

## P8/P9 boundaries

- **P8** (competition lifecycle automation) may eventually transition a
  competition to `sold_out` once its allocated ticket count reaches
  `max_entries` — P7 does not do this itself (P7 only enforces the
  ceiling at allocation time; deciding when/whether to flip
  `Competition.status` in response is P8's lifecycle-engine job).
- **P9** (draw engine) reads `tickets`/`entries` joined to `Order.status
  = paid` to determine the eligible pool — P7 does not select winners,
  does not introduce randomness anywhere in this phase, and does not
  reserve/mark tickets as "drawn."
- Neither is implemented, referenced, or scaffolded here beyond the doc
  notes above.
