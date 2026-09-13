# Ticket Engine API (P7)

Read-only customer access to allocated entries/tickets, plus the
paid-order → allocation pipeline that produces them. See
[`docs/TICKET_ENGINE_DESIGN_P7.md`](TICKET_ENGINE_DESIGN_P7.md) for the
full design rationale (locking strategy, idempotency, numbering) — this
document is the endpoint/operational reference.

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

- **Entry**: one row per `OrderItem`, created once its order is paid and
  allocation runs. Immutable after creation.
- **Ticket**: one row per unit of quantity within an `Entry`.
  `ticket_number` is unique **per competition** (never globally, never
  the same as the internal `tickets.id`) — sequential starting at 1.

## Allocation pipeline

```
Order.status → paid (P6, PaymentService::markPaid)
    │
    ▼ (App\Events\OrderPaid, fired via DB::afterCommit — only on the
    │  call that actually performs the transition)
    ▼
App\Listeners\DispatchTicketAllocation (auto-discovered, not queued
    itself — dispatches the job below)
    │
    ▼
App\Jobs\AllocateTicketsForOrder (queued, on the existing `redis` queue)
    │
    ▼
App\Services\Tickets\TicketAllocationService::allocateOrder()
    │
    ▼
Entry + Ticket rows committed together, per order item
```

No code was added to `PaymentService` beyond firing the event, and none
was added to `PaymentWebhookController` at all — both the browser
verification endpoint and the webhook path trigger allocation
identically, automatically, because both already call into
`PaymentService`.

Allocation is asynchronous (queued), not part of the
`POST /v1/payments/verify` request/response cycle — a customer
following the checkout flow may see `entries: []` on the order for a
brief moment immediately after payment, before the queue worker
processes the job. The frontend polls for this (see "Frontend
integration").

## Endpoints

Both require `auth:sanctum` and are scoped to the authenticated user —
a cross-user id returns `404`. **Read-only: there is no mutation
endpoint, and none should be added** — customers cannot edit or delete
entries/tickets.

### `GET /v1/entries`

Paginated, newest first.

```json
{
  "data": [
    {
      "id": 12,
      "competition": { "id": 3, "slug": "bmw-m4", "title": "BMW M4 Competition" },
      "order_reference": "RG-935479",
      "quantity": 4,
      "ticket_numbers": [1, 2, 3, 4],
      "allocated_at": "2026-09-13T15:24:52+00:00"
    }
  ],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 10, "total": 1 }
}
```

### `GET /v1/entries/{entry}`

Same shape as one item above. `404` for an unknown id or one that
doesn't belong to the caller.

## Extended: `GET /v1/orders/{reference}` / `GET /v1/orders` (P5/P6, extended)

Both now also include an `entries` field — **every** entry belonging to
that order (not just the latest, unlike `payment`), empty until
allocation completes:

```json
{
  "data": {
    "...": "... (unchanged P5/P6 fields)",
    "entries": [
      {
        "id": 12,
        "competition": { "id": 3, "slug": "bmw-m4", "title": "BMW M4 Competition" },
        "order_reference": "RG-935479",
        "quantity": 4,
        "ticket_numbers": [1, 2, 3, 4],
        "allocated_at": "2026-09-13T15:24:52+00:00"
      }
    ]
  }
}
```

## Frontend integration

- `OrderConfirmation` (checkout success page) shows each order line's
  ticket numbers once its matching entry appears, and a truthful
  "Ticket allocation in progress" message (never a fake ticket number)
  while `order.status` is paid/processing/completed but `entries` is
  still empty — it polls `GET /v1/orders/{reference}` every 3s (up to
  10 attempts) until entries appear, then stops.
- `/account/entries` — a full listing of the customer's entries across
  every order/competition, using `GET /v1/entries`. Linked from
  `/account`.

## Security

- Every entry/ticket is scoped to `user_id` — ownership enforced via
  `$request->user()->entries()`, never a bare id lookup. Cross-user
  access returns `404`, matching P5/P6's cart/order/payment convention.
- No ticket mutation endpoint exists.
- No admin/audit internals, RNG state, or draw information is exposed
  (none exist yet — P9 owns draws).
- Ticket numbers are sequential per competition, never derived from or
  equal to any internal database id.

## Concurrency & capacity

See `docs/TICKET_ENGINE_DESIGN_P7.md` "Transaction & locking strategy"
and "Concurrency test" for the full explanation and the actual
verification (two concurrent 6-ticket allocations against a 10-ticket
cap; two concurrent 6-ticket allocations against one user's 10-ticket
per-user limit) — both run against a real MySQL database via forked
processes, not sequential calls. In short:

- The competition row is locked (`SELECT ... FOR UPDATE`) for the
  duration of its capacity/per-user checks and ticket insert.
- The capacity/per-user `COUNT` queries are themselves locking reads —
  this is not optional; a plain `SELECT COUNT(*)` under MySQL's default
  `REPEATABLE READ` isolation can still return a stale count even while
  holding the competition row lock, which the concurrency test caught
  empirically.
- `unique(competition_id, ticket_number)` is the database-level safety
  net behind the row lock.

## Idempotency & failure/retry behavior

Calling `TicketAllocationService::allocateOrder()` 0, 1, or many times
for the same order always converges to the same end state — see
`docs/TICKET_ENGINE_DESIGN_P7.md` "Idempotency strategy". A capacity or
per-user-limit conflict fails the queued job immediately (no pointless
retries — it's a deterministic outcome) and lands in Laravel's own
`failed_jobs` table; the order **stays paid**, untouched — resolving a
paid-but-unallocated order (refund, waiting for capacity, manual
override) is an operational decision outside this phase's scope.

## P8/P9 boundaries

- **P8** (competition lifecycle) may transition a competition to
  `sold_out` once its ticket count reaches `max_entries` — not done
  here.
- **P9** (draw engine) selects winners from `tickets`/`entries` joined
  to `Order.status = paid` — no randomness, winner selection, or draw
  state exists in this phase.
