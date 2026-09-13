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
- **P4 — Competition domain**: CRUD + Filament resource; frontend switches
  from `src/data/competitions.ts` to real API calls.
- **P5 — Orders**: order creation flow, replacing the `sessionStorage`
  mock in `src/lib/order.ts`.
- **P6 — Ticket engine**: concurrency-safe allocation/reservation/release,
  per-user and per-competition limits, sold-out handling — needs its own
  design pass (row locking, idempotency) before any code is written.
- **P7 — Payments**: provider integration, webhook handling, idempotency
  keys, replacing `PaymentPlaceholder`.
- **P8 — Competition lifecycle**: closing rules, sold-out detection,
  scheduled jobs (this is what P1's scheduler infrastructure is for).
- **P9 — Draw engine**: eligible-ticket snapshot, winner selection,
  auditability, duplicate-execution prevention — highest-trust component,
  needs a dedicated security/audit review before build.
- **P10 — Winner / prize claim + notifications**: claim workflow, delivery
  tracking, email/SMS.
- **P11 — Filament admin**: build out resources once the underlying
  models are stable.
- **P12 — Observability + testing hardening**: Sentry/logging, load tests
  on the ticket engine, concurrency tests on draw execution, the
  payment/ticket/draw test suite called out in the P0 audit.

Legal/compliance rules (age verification, geographic restrictions, consent,
data retention) are configurable business rules to build alongside the
relevant domain (mostly P4/P6) — final requirements need qualified
legal/compliance review, not just engineering judgment.
