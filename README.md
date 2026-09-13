# RaffleGuru

A UK competitions platform. This repo is a monorepo split into a Next.js
frontend and a Laravel API, with Docker-based infrastructure for local
development and production.

## Status: demo UI + production foundation

- **`apps/web`** — the approved customer-facing UI. Login/register/forgot-
  password/reset-password/email-verification and the `/account` route are
  real, backed by the Laravel API (see
  [`docs/AUTHENTICATION.md`](docs/AUTHENTICATION.md)). Cart/checkout are
  still a frontend-only demo (`localStorage`/`sessionStorage`, no real
  orders/payments). See [`apps/web/CLAUDE.md`](apps/web/CLAUDE.md) and
  [`apps/web/DESIGN_SYSTEM.md`](apps/web/DESIGN_SYSTEM.md).
- **`apps/api`** — a Laravel application with a database foundation
  (`users`/`competitions`/`prizes`, see
  [`docs/SCHEMA_DESIGN_P2.md`](docs/SCHEMA_DESIGN_P2.md)) and customer
  authentication. No Competition/Ticket/Order/Payment/Draw endpoints exist
  yet — see [`docs/ROADMAP.md`](docs/ROADMAP.md) for what's next and
  [`docs/PRODUCTION_ARCHITECTURE.md`](docs/PRODUCTION_ARCHITECTURE.md) for
  how the pieces fit together.

Nothing here is production-live yet — this is the foundation the real
product gets built on.

## Repository layout

```
apps/
  web/            Next.js frontend (App Router)
  api/            Laravel API
infrastructure/
  docker/         Dockerfiles (php, web)
  nginx/          Reverse proxy configs (dev + prod)
docs/             Architecture + roadmap docs
.github/workflows/  CI
docker-compose.yml       Local dev stack
docker-compose.prod.yml  Production topology (reference/deploy target)
```

## Local development

### Option A — Docker (recommended, matches production topology)

Requires Docker + Docker Compose.

```bash
cp apps/web/.env.example apps/web/.env.local   # optional, has a working default
cp apps/api/.env.example apps/api/.env
docker compose up --build
```

This starts: MySQL, Redis, the Laravel API (hot-reloading dev server),
a queue worker, a scheduler, the Next.js dev server, and Nginx in front of
everything.

- Site: <http://localhost:8080>
- API health: <http://localhost:8080/api/v1/health> and
  `http://localhost:8080/api/v1/health/status` (reports MySQL/Redis
  reachability)
- Direct access (bypassing Nginx), for debugging: web on
  `localhost:3100`, API on `localhost:8001`
- MySQL: `localhost:3310`, Redis: `localhost:6390` (both loopback-only)

Stop everything: `docker compose down` (add `-v` to also wipe the database
volume).

Run migrations / tests inside the containers:

```bash
docker compose exec api php artisan migrate
docker compose exec api vendor/bin/phpunit
```

**Known rough edge:** if you rebuild/recreate the `api` container while
`nginx` keeps running, Nginx may cache the old container's IP and return a
502 until you `docker compose restart nginx`. See
[`docs/PRODUCTION_ARCHITECTURE.md`](docs/PRODUCTION_ARCHITECTURE.md) for
why, and the P1 report's Known Limitations for the proper fix.

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
vendor/bin/phpunit      # or: composer test
```

## Production

```bash
cp .env.example .env    # fill in real DB credentials + NEXT_PUBLIC_API_URL
cp apps/api/.env.example apps/api/.env   # fill in APP_KEY, APP_ENV=production, APP_DEBUG=false, ...
docker compose -f docker-compose.prod.yml up --build -d
```

See [`docs/PRODUCTION_ARCHITECTURE.md`](docs/PRODUCTION_ARCHITECTURE.md)
for the full topology, environment variables, and the AWS deployment
direction (not provisioned yet).

## CI

`.github/workflows/ci.yml` runs on every push/PR: frontend lint + test +
build, backend Composer install + PHPUnit.
