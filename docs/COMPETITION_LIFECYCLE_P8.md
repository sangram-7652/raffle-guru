# P8 — Competition Lifecycle Automation

Server-controlled, time- and capacity-based transitions on top of the
existing `CompetitionStatus` enum (P2). P8 does not introduce a new
status representation, a second ticket counter, or any draw/winner
logic — it only makes the existing `status` column move itself,
reliably, under concurrency.

## State machine

The full `CompetitionStatus` enum (P2) is preserved exactly:

```
draft → scheduled → active → sold_out → closed → draw_pending → draw_in_progress → drawn → completed
```

P8 owns the first five arrows. The last three
(`draw_pending → draw_in_progress → drawn → completed`) are P9/P10's —
nothing in this phase ever produces them, and the transition matrix
below has no path to any of them.

## Transition matrix (authoritative — `CompetitionLifecycleService::MATRIX`)

| From | To | Trigger |
|---|---|---|
| `scheduled` | `active` | `starts_at <= now()` |
| `active` | `sold_out` | allocated tickets reach `max_entries` (capacity) |
| `active` | `closed` | `ends_at <= now()` (time ran out before selling out) |
| `sold_out` | `closed` | unconditional, next lifecycle sweep |
| `closed` | `draw_pending` | `draw_at <= now()` |

Every other `(from, to)` pair — including anything targeting
`draw_in_progress`, `drawn`, or `completed`, and anything moving
*backwards* (e.g. `closed → active`) — is rejected. `draft` has no
automatic transition at all. Its only edge is the editorial publish
`draft → scheduled` (P11.9), initiated by an admin through the
"Transition status" action and recorded in the same audit log. A draft
can never reach `active` directly, and `scheduled` is not publicly
visible: public visibility begins at `scheduled → active`.

### Why `sold_out` and `closed` are separate, and why `sold_out → closed` is unconditional

`sold_out` and `closed` both mean "not accepting new entries" — they
differ only in *why* (ran out of tickets vs. ran out of time), which is
worth surfacing to a customer ("Sold out!" reads better than a generic
"Closed"). Rather than forking `sold_out` and `closed` into two
independent paths to `draw_pending` (which the brief explicitly allows,
"`sold_out → closed` OR `draw_pending`, depending on the agreed
lifecycle rules"), this implementation funnels both into a single
`closed → draw_pending` step gated on `draw_at`. A sold-out competition
already satisfies "closed"'s core property — there's no capacity left,
no matter what time it is — so `sold_out → closed` never waits on
`ends_at`; it happens on the very next lifecycle sweep. This keeps
exactly one path to `draw_pending`, which is simpler to test and reason
about than two.

### Nullable dates are handled explicitly, not defaulted

- `ends_at` is nullable (P2). A competition with no `ends_at` never
  auto-closes on time — it only reaches `closed` via `sold_out`. An
  operator who wants a guaranteed close time must set `ends_at`.
- `draw_at` is nullable (P2). A `closed` competition with no `draw_at`
  stays `closed` indefinitely — P8 does not invent a fallback draw
  time. (In practice every competition created through the existing
  factory/seed data sets one; this is a defensive, not a normal, case.)

## Where each transition actually happens

- **`scheduled → active`**, **`active → closed`**, **`sold_out →
  closed`**, **`closed → draw_pending`**: all time-based, all evaluated
  by the `competition:lifecycle` command (see below) — nothing else
  touches them.
- **`active → sold_out`**: capacity-based, and deliberately triggered
  **inline, inside `TicketAllocationService::allocateItem()`**, not by
  the periodic command. The competition row is already locked
  (`SELECT ... FOR UPDATE`) there for the capacity check itself (P7) —
  reusing that exact lock to also flip the status is free (no new
  lock, no extra round trip) and immediate (a customer allocating the
  last ticket sees `sold_out` reflected the instant their order pays,
  not up to a minute later on the next scheduler tick). This is
  exactly what "the lifecycle implementation must use the existing
  ticket/entry source of truth" and "if P7 already has the correct
  locking/query approach, reuse it" call for. `TicketAllocationService`
  gained one new constructor dependency
  (`CompetitionLifecycleService`) and one extra call after the ticket
  insert; no other change to its logic, transaction boundaries, or
  exception types.

Every transition, regardless of trigger, goes through
`CompetitionLifecycleService::transition()` — the single place that
checks the matrix, locks the row, re-reads its current status, applies
the update, and writes the audit row. Nothing else in the codebase
writes to `competitions.status`.

## `CompetitionLifecycleService::transition()`

```
transition(Competition $competition, CompetitionStatus $to, string $reason): bool

    DB::transaction:
        lock the competition row FOR UPDATE, re-read its CURRENT status
            — never trust the $competition instance the caller passed in;
              it may be stale (queried moments ago, or already mutated by
              a concurrent worker)
        if (current status, $to) is not in the matrix: return false
            — a safe no-op, not an exception. "Invalid transition" and
              "already transitioned by someone else" look identical from
              here, which is exactly the idempotency this phase requires:
              running the lifecycle command twice, or two workers racing
              on the same row, both just see the second attempt quietly
              do nothing.
        update status
        write a CompetitionLifecycleEvent row (competition_id, from,
            to, reason, created_at)
        log competition.lifecycle.transitioned
        return true
```

Calling `transition()` from inside an already-open transaction (as
`TicketAllocationService` does) is safe: MySQL/InnoDB re-locking a row
your own connection already holds is a harmless no-op, and Laravel's
`DB::transaction()` uses a savepoint for the nested call.

## Automation: `php artisan competition:lifecycle`

A single command (`app/Console/Commands/CompetitionLifecycleCommand.php`),
scheduled via `routes/console.php`:

```php
Schedule::command('competition:lifecycle')->everyMinute()->withoutOverlapping();
```

`everyMinute()` is a simple, production-safe cadence for a
time-resolution business rule (nobody needs a competition to activate
within sub-minute precision) — cheap enough to run constantly, coarse
enough not to hammer the database. `withoutOverlapping()` (Laravel's
cache-based mutex, backed by the existing Redis cache store) stops a
slow run from stacking a second concurrent invocation from the
*scheduler* itself; the per-row `SELECT ... FOR UPDATE` inside
`transition()` is what makes it safe even if that's ever bypassed (two
manually-run copies of the command, for instance).

The command runs four independent, chunked queries — each finds
candidates, then calls `transition()` per row inside a try/catch so one
malformed competition can't halt the batch:

1. `status = scheduled AND starts_at <= now()` → attempt `active`.
2. `status = active AND ends_at IS NOT NULL AND ends_at <= now()` →
   attempt `closed`.
3. `status = sold_out` → attempt `closed` (unconditional).
4. `status = closed AND draw_at IS NOT NULL AND draw_at <= now()` →
   attempt `draw_pending`.

Each uses `chunkById(100, ...)` — competitions are never loaded in
bulk into memory, and this stays correct at any scale.

Remains fully manually executable (`php artisan competition:lifecycle`)
for operations/testing/debugging — the scheduler is just a cron trigger
around the same command.

## Concurrency

Two independent guarantees, at two different layers:

1. **Row-level (the real guarantee)**: `transition()`'s `SELECT ... FOR
   UPDATE` + re-read-under-lock means two concurrent attempts on the
   *same* competition — two lifecycle command instances, or a lifecycle
   command racing a ticket allocation reaching capacity at the same
   moment — serialize on that row. Whichever commits first wins; the
   second sees the already-updated status and its own matrix check
   fails closed (returns `false`, no-op). Verified with a real MySQL,
   forked-process test (`LifecycleConcurrencyTest`), the same technique
   `docs/TICKET_ENGINE_DESIGN_P7.md`'s `ConcurrencyTest` uses — sequential
   calls prove nothing about this.
2. **Scheduler-level (a courtesy, not the real guarantee)**:
   `withoutOverlapping()` avoids wasted duplicate work when the
   scheduler's own process model would otherwise let a slow run stack.

The ticket-allocation ↔ lifecycle race the brief calls out explicitly
("Worker A checks active, Worker B reaches max_entries, Worker A tries
to allocate") cannot actually happen the way it's phrased, by
construction: nothing in this codebase ever "checks active" and then
allocates without also holding the competition's row lock across both
steps — `TicketAllocationService::allocateItem()` locks the competition
row *before* counting tickets and *keeps holding it* through the insert
(and now the sold-out transition too), so a concurrent lifecycle sweep
attempting `active → closed` on the same row simply queues behind it.
Order/cart validation (P5) separately re-resolves the competition fresh
via `scopePurchasable()` at both add-to-cart and order-creation time,
so a competition that has already flipped to `sold_out`/`closed` (by
either path) is rejected before allocation is ever attempted for it.

## Capacity: no second counter

`TicketAllocationService` already treats `COUNT(tickets WHERE
competition_id = ?)` (locked) as the sole source of truth for how many
entries a competition has (P7). P8 adds nothing here except reading
that same count's result to decide `active → sold_out` — there is no
`competitions.tickets_sold` column, no cache, no denormalized counter
that could drift. "Capacity remains authoritative from tickets/entries"
is true by construction, not by a rule this phase has to separately
enforce.

## `ends_at` / closing

Purchasability was already, and remains, gated entirely by
`Competition::scopePurchasable()` (`status = active` — P5), used by
both `CartController::store()` (add to cart) and
`OrderController::store()` (order creation, re-checked fresh at
checkout time even if it was purchasable when added to the cart). P8
changes **zero** lines in the purchase path — once the lifecycle
service (or the inline capacity check) flips a competition's status
away from `active`, `scopePurchasable()` starts excluding it
automatically, everywhere it's already used. This is exactly the "reuse
existing... logic wherever possible" instruction, applied literally:
the enforcement point already existed; P8 only had to make sure
`status` itself stays correct over time.

## Draw readiness (`draw_pending`)

`draw_pending` means, and only means, "P9 may now run the draw for this
competition." P8 establishes it (once `closed` and past `draw_at`) and
does nothing else with it — no ticket snapshot, no eligible-entry list,
no randomness, no winner record. P9 is expected to read the
`entries`/`tickets` tables directly (joined to `Order.status = paid`,
per `docs/TICKET_ENGINE_DESIGN_P7.md`'s own P9 handoff note) rather than
receiving anything precomputed by this phase.

## Audit trail

New table, `competition_lifecycle_events` — the "smallest appropriate"
mechanism (no existing audit architecture was found to reuse): `id`,
`competition_id` (restrictOnDelete), `from_status`, `to_status`,
`reason` (short string, e.g. `"starts_at reached"`, `"capacity
reached"`), `created_at` only (an event log entry is never edited, so
no `updated_at`). One row per **actual** transition (a rejected/no-op
attempt writes nothing) — written inside the same transaction as the
status update itself, so the audit trail and the status can never
disagree about whether a transition happened.

## Admin / Filament

No Filament installation exists anywhere in this codebase (confirmed by
inspection) — there is nothing to refactor or lock down. If/when P11
adds admin competition management, it must call
`CompetitionLifecycleService::transition()` rather than writing
`status` directly, to keep this the single enforcement point; noted
here so that future work doesn't quietly reopen the bypass.

## API impact

None beyond `status` itself now changing over time — `CompetitionResource`
already serializes `status` verbatim, and `scopePublic()` (P4)'s
visibility table is unchanged (`scheduled`/`draft` hidden, everything
`active` onward visible, including all of `sold_out`/`closed`/
`draw_pending`). No new fields, no new endpoints, nothing internal
(locks, scheduler state, audit ids) exposed.

## What P8 deliberately does not implement

Draw execution, random winner selection, any RNG (Mersenne Twister or
otherwise), live draw, instant win, a winners table, winner claims,
prize delivery, or any audit system beyond the lifecycle-transition log
above. `draw_in_progress`, `drawn`, and `completed` are not reachable
from any code path this phase adds. All of that is P9 (draws) / P10
(winners & claims).
