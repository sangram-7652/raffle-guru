# RaffleGuru — Production Implementation Roadmap

Carried over from the P0 architecture audit. Each phase builds on the
previous one; do not skip ahead (e.g. don't build the draw engine before
ticket allocation is concurrency-safe).

- **P1 — Infrastructure foundation** (this phase, complete): Next.js/Laravel
  monorepo split, Docker (MySQL, Redis, queue, scheduler, Nginx), CI,
  health checks. No domain logic.
- **P2 — Database foundation** (complete): `users`/`competitions`/`prizes`
  schema, models, factories, seeders — see
  [`SCHEMA_DESIGN_P2.md`](SCHEMA_DESIGN_P2.md). No API endpoints yet.
- **P3 — Authentication** (complete): Sanctum SPA (cookie-session) auth —
  register/login/logout, email verification, password reset, rate
  limiting, `/account` as a protected route. See
  [`AUTHENTICATION.md`](AUTHENTICATION.md). No orders/tickets/etc. yet.
- **P4 — Competition domain** (complete): public read API
  (`GET /api/v1/competitions`, `GET /api/v1/competitions/{slug}`),
  frontend switched from mock data to real API calls. See
  [`COMPETITIONS_API.md`](COMPETITIONS_API.md). No write/admin endpoints
  yet — there's no role/permission concept to gate them on (that's P11).
- **P5 — Orders** (complete): `carts`/`cart_items`/`orders`/`order_items`
  schema, server-side price integrity, authenticated cart/order API,
  frontend cart/checkout switched from the P1 mock (`src/lib/order.ts`,
  now removed) to the real API. Orders are created `pending` only — no
  payment or ticket logic. See [`ORDERS_API.md`](ORDERS_API.md).
- **P6 — Payments** (complete): Razorpay integration behind a
  `PaymentGatewayInterface` abstraction, `payments`/`payment_webhooks`
  schema, authenticated payment-initiation/verification endpoints, an
  unauthenticated signature-verified webhook, idempotent order/payment
  transitions, retry-after-failure. Orders now reach `paid`. See
  [`PAYMENTS_API.md`](PAYMENTS_API.md) /
  [`PAYMENTS_DESIGN_P6.md`](PAYMENTS_DESIGN_P6.md). No refund
  initiation, no ticket logic.
- **P7 — Ticket engine** (complete): `entries`/`tickets` schema,
  concurrency-safe allocation (`TicketAllocationService`, competition
  row locking, verified with real forked-process MySQL concurrency
  tests) triggered off `Order.status = paid` via an `OrderPaid` event —
  no coupling added to `PaymentService` beyond firing it, none added to
  the payment webhook at all. Per-competition and per-user limits
  enforced atomically at allocation time (the true enforcement
  boundary — P5's cart-time check is a UX nicety only). Read-only
  `/v1/entries` API, `/account/entries` page. No draw/winner logic. See
  [`TICKET_ENGINE_API.md`](TICKET_ENGINE_API.md) /
  [`TICKET_ENGINE_DESIGN_P7.md`](TICKET_ENGINE_DESIGN_P7.md).
- **P8 — Competition lifecycle** (complete): server-controlled,
  time-/capacity-based status automation
  (`scheduled→active→sold_out/closed→draw_pending`) centralized in
  `CompetitionLifecycleService`'s transition matrix — the single place
  `competitions.status` is ever written — automated via a scheduled
  `competition:lifecycle` command plus an inline capacity hook in
  `TicketAllocationService`. No draw/winner logic. See
  [`COMPETITION_LIFECYCLE_P8.md`](COMPETITION_LIFECYCLE_P8.md).
- **P9 — Draw engine** (complete): the final leg,
  `draw_pending→draw_in_progress→drawn`, added as two new edges on the
  same `CompetitionLifecycleService` matrix — no second lifecycle
  mechanism. Eligible tickets are those whose order is `paid` (a direct
  `tickets ⋈ orders` join); a snapshot (count/range/checksum, never a
  row-by-row copy) makes each draw auditable; winner selection uses
  `random_int()` rejection sampling by ticket_number, bounded query cost
  regardless of competition size. Event-driven trigger
  (`CompetitionDrawPending` → `DrawCompetitionJob`) mirrors the P6→P7
  handoff, with a scheduled sweep (`competition:draw`) as a crash-recovery
  safety net. No winner claims, prize delivery, or `drawn→completed` —
  those are P10's. See [`DRAW_ENGINE_P9.md`](DRAW_ENGINE_P9.md).
- **P10 — Winner, claim & prize fulfilment engine** (complete): resolves
  `draw_results.ticket_id → tickets.user_id` into a `winners` row —
  never recalculates P9's winner — then owns the customer-facing
  lifecycle `pending_contact→contacted→verification_pending→verified→
  claim_approved→delivery_pending→delivered→completed`, each strictly
  forward-only through `WinnerLifecycleService`'s transition matrix (the
  single place `winners.status` is ever written), same locking
  philosophy as P7/P8/P9. One customer-initiated action exists in the
  whole domain (submitting a claim); every other transition is
  server/operator-only. Event-driven trigger (`CompetitionDrawn` →
  `CreateWinnerJob`) mirrors the P8→P9 handoff, with `winners:reconcile`
  as the crash-recovery safety net. Adds `drawn→completed` as one new
  edge on `CompetitionLifecycleService`'s existing matrix — reached only
  once a winner's prize has actually been delivered, never automatically
  right after the draw. No KYC provider, courier integration, or admin
  UI — those are explicitly out of scope; see
  [`WINNER_CLAIM_P10.md`](WINNER_CLAIM_P10.md) §19 for the full P11
  boundary.
- **P11 — Filament admin**: build out resources once the underlying
  models are stable.
- **P12 — Observability + testing hardening**: Sentry/logging, load tests
  on the ticket engine, concurrency tests on draw execution, the
  payment/ticket/draw test suite called out in the P0 audit.

Legal/compliance rules (age verification, geographic restrictions, consent,
data retention) are configurable business rules to build alongside the
relevant domain (mostly P4/P6) — final requirements need qualified
legal/compliance review, not just engineering judgment.
