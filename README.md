# RaffleGuru

A UK competitions (skill-based prize draw) platform. Monorepo: a
Next.js frontend and a Laravel API, Docker-based infrastructure for
both local development and production.

## What is RaffleGuru?

Customers browse competitions, buy numbered entries, and a draw at a
scheduled time picks one winning ticket per competition. The full
lifecycle — competition creation → ticket sales → automatic lifecycle
transitions → draw → winner → claim → prize fulfilment — is
implemented end to end, with a Filament admin panel for staff
operations. See [`docs/USER_GUIDE.md`](docs/USER_GUIDE.md) for the
customer-facing walkthrough and
[`docs/OPERATIONS_RUNBOOK.md`](docs/OPERATIONS_RUNBOOK.md) for how
staff actually operate it day to day.

## Architecture

```
apps/
  web/            Next.js frontend (App Router)
  api/            Laravel API (Filament admin panel at /admin)
infrastructure/
  docker/         Dockerfiles (php, web)
  nginx/          Reverse proxy configs (dev + prod)
docs/             Architecture, design, and operations documentation
load-tests/       k6 load-test scripts
.github/workflows/  CI
docker-compose.yml       Local dev stack
docker-compose.prod.yml  Production topology
```

Next.js → Nginx → Laravel API (php-fpm) → MySQL 8.4 + Redis, with a
dedicated queue worker and scheduler container. Full reasoning:
[`docs/PRODUCTION_ARCHITECTURE.md`](docs/PRODUCTION_ARCHITECTURE.md).

## Development setup

### Option A — Docker (recommended, matches production topology)

Requires Docker + Docker Compose.

```bash
cp apps/web/.env.example apps/web/.env.local   # optional, has a working default
cp apps/api/.env.example apps/api/.env
docker compose up --build
```

This starts: MySQL, Redis, the Laravel API (hot-reloading dev server),
a queue worker, a scheduler, the Next.js dev server, and Nginx in front
of everything.

- Site: <http://localhost:8080>
- Admin panel: <http://localhost:8080/admin> — bootstrap the first
  SuperAdmin with `docker compose exec api php artisan admin:create-super`
- API health: <http://localhost:8080/api/v1/health> and
  `http://localhost:8080/api/v1/health/status` (reports MySQL/Redis
  reachability)
- Direct access (bypassing Nginx), for debugging: web on
  `localhost:3100`, API on `localhost:8001`
- MySQL: `localhost:3310`, Redis: `localhost:6390` (both loopback-only)

Stop everything: `docker compose down` (add `-v` to also wipe the
database volume).

Run migrations inside the containers:

```bash
docker compose exec api php artisan migrate
```

### Option B — run each app directly on the host

Frontend:

```bash
cd apps/web
npm install
npm run dev        # http://localhost:3000
npm run lint
npm run test        # Vitest
npm run build
```

Backend (needs PHP 8.5+, Composer; MySQL/Redis reachable — e.g. via
`docker compose up mysql redis` and the published ports above):

```bash
cd apps/api
composer install
cp .env.example .env   # edit DB_*/REDIS_* to match wherever MySQL/Redis run
php artisan key:generate
php artisan migrate
php artisan serve       # http://localhost:8000
```

## Testing

```bash
# Frontend
cd apps/web && npm run test

# Backend — fast SQLite suite (everything except real-MySQL-concurrency tests)
docker compose exec api vendor/bin/phpunit --exclude-group mysql

# Backend — real MySQL concurrency tests (row-locking under genuine
# concurrent transactions; see docs/CI_TEST_INFRASTRUCTURE.md for why
# these need a real database, not SQLite's :memory:)
docker compose exec -e TESTING_DB=mysql api vendor/bin/phpunit --group mysql
```

CI (`.github/workflows/ci.yml`) runs three jobs on every push/PR:
Frontend (lint + test + build), Backend (the SQLite suite), and Backend
MySQL concurrency (the real-MySQL suite, including a throwaway service
container).

## Production documentation

Start here for anything deployment/operations-related:

- [`docs/PRODUCTION_DEPLOYMENT.md`](docs/PRODUCTION_DEPLOYMENT.md) —
  the full deployment runbook (prerequisites through rollback)
- [`docs/GO_LIVE_CHECKLIST.md`](docs/GO_LIVE_CHECKLIST.md) — what's
  required before going live vs. optional vs. genuinely not implemented
- [`docs/PRODUCTION_INCIDENT_RUNBOOK.md`](docs/PRODUCTION_INCIDENT_RUNBOOK.md) —
  what to do when something breaks in production
- [`docs/PRODUCTION_SMOKE_TEST.md`](docs/PRODUCTION_SMOKE_TEST.md) /
  [`docs/STAGING_VERIFICATION.md`](docs/STAGING_VERIFICATION.md) —
  post-deploy and staging verification sequences
- [`docs/BACKUPS_DISASTER_RECOVERY.md`](docs/BACKUPS_DISASTER_RECOVERY.md) —
  backup/restore, and the honest current state of disaster recovery
  (local-only backups — see that doc)
- [`docs/LOAD_AND_CONCURRENCY.md`](docs/LOAD_AND_CONCURRENCY.md) —
  concurrency-safety audit and load-test methodology/results
- [`docs/OPERATIONS_RUNBOOK.md`](docs/OPERATIONS_RUNBOOK.md) — admin,
  payment, and competition/draw day-to-day operations
- [`docs/PRODUCTION_ARCHITECTURE.md`](docs/PRODUCTION_ARCHITECTURE.md) —
  how all the pieces fit together, including the "AWS direction"
  (documentation only, not provisioned)

## Major design/API docs

Per-domain design and API reference, in build order:
[`SCHEMA_DESIGN_P2.md`](docs/SCHEMA_DESIGN_P2.md) ·
[`AUTHENTICATION.md`](docs/AUTHENTICATION.md) ·
[`COMPETITIONS_API.md`](docs/COMPETITIONS_API.md) ·
[`ORDERS_API.md`](docs/ORDERS_API.md) ·
[`PAYMENTS_API.md`](docs/PAYMENTS_API.md) /
[`PAYMENTS_DESIGN_P6.md`](docs/PAYMENTS_DESIGN_P6.md) ·
[`TICKET_ENGINE_API.md`](docs/TICKET_ENGINE_API.md) /
[`TICKET_ENGINE_DESIGN_P7.md`](docs/TICKET_ENGINE_DESIGN_P7.md) ·
[`COMPETITION_LIFECYCLE_P8.md`](docs/COMPETITION_LIFECYCLE_P8.md) ·
[`DRAW_ENGINE_P9.md`](docs/DRAW_ENGINE_P9.md) ·
[`WINNER_CLAIM_P10.md`](docs/WINNER_CLAIM_P10.md) ·
[`ADMIN_USERS_P11.md`](docs/ADMIN_USERS_P11.md). See
[`ROADMAP.md`](docs/ROADMAP.md) for what phase built what, and
[`CI_TEST_INFRASTRUCTURE.md`](docs/CI_TEST_INFRASTRUCTURE.md) for why
the test suite runs on two different databases.

## Current limitations

Verified true as of this phase (P12.4) — not aspirational:

- **No off-server backup / tested disaster recovery.** Backups are
  local to the production Docker host — see
  [`BACKUPS_DISASTER_RECOVERY.md`](docs/BACKUPS_DISASTER_RECOVERY.md).
- **No TLS/HTTPS termination in this repository.** Nginx serves plain
  HTTP on port 80 only — see
  [`PRODUCTION_DEPLOYMENT.md`](docs/PRODUCTION_DEPLOYMENT.md) §2 for
  what you must add before going live.
- **No automated refunds** — Razorpay refunds are processed directly
  in the Razorpay dashboard; this app only reflects the outcome.
- **No courier/shipping integration, no KYC provider** — prize
  fulfilment is entirely manual/operator-entered.
- **No deployment automation** — deploys are a manual, documented
  command sequence (`PRODUCTION_DEPLOYMENT.md`), not a CI/CD pipeline.
- **Write-path load testing has not been run** against an isolated
  staging environment yet — see
  [`STAGING_VERIFICATION.md`](docs/STAGING_VERIFICATION.md) §16.
- The frontend's design system and customer-facing conventions are
  documented separately in
  [`apps/web/CLAUDE.md`](apps/web/CLAUDE.md)/[`apps/web/DESIGN_SYSTEM.md`](apps/web/DESIGN_SYSTEM.md).
