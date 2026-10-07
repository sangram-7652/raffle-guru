# RaffleGuru — Production Architecture

Status: **infrastructure foundation only** (P1). No competition, ticket, order,
payment, or draw domain logic exists yet — see the P1 report for exactly
what P1 did and did not build, and [ROADMAP.md](ROADMAP.md) for what comes
next.

## Topology

```
                              Internet
                                 │
                                 ▼
                              Nginx
                          /            \
                         /              \
                        ▼                ▼
                   Next.js (web)     Laravel API (api)
                        │                │  │
                        │                │  └── Queue Worker (queue)
                        │                │  └── Scheduler (scheduler)
                        │                ▼
                        │            MySQL + Redis
                        │                │
                        └──── (no direct access) ──┘
```

- **Nginx** is the only public entry point. It path-routes `/` to the
  Next.js server and `/api` to the Laravel API — both origins are the same
  from the browser's point of view, so no CORS configuration is needed for
  the web frontend talking to its own API. Upstreams are addressed by
  Docker Compose service name (`web`, `api`) through Docker's embedded DNS
  (`127.0.0.11`), resolved lazily per-request via an nginx `resolver` +
  variable rather than once at startup — see the comment in
  `infrastructure/nginx/dev.conf`/`prod.conf` — so Nginx doesn't need
  either container to already be up when it starts, and keeps routing
  correctly after `web`/`api` are recreated with a new address, without a
  manual Nginx restart.
- **Next.js** (`apps/web`) renders the customer-facing site. In production
  it runs as a standalone Node server (`next build` with `output:
  "standalone"`), not `next dev`.
- **Laravel API** (`apps/api`) is a modular monolith — one codebase, one
  deployable unit, internally organized by domain (see
  [Module Map](#module-map) below) rather than split into services. In
  production it runs behind `php-fpm`, not the built-in dev server.
- **MySQL** and **Redis** are never reachable from outside the Docker
  network — no published ports in `docker-compose.prod.yml`.
- **Queue worker** and **scheduler** are separate long-running containers
  built from the same PHP image as the API, so they can scale
  independently of request-serving capacity.

## Why a monolith, not microservices

A single Laravel application can cleanly own Competition, Ticket, Order,
Payment, Draw, and Winner at this scale. Splitting those into separate
services now would add network calls, distributed-transaction problems,
and deployment complexity with no corresponding benefit — there is no
team-scaling or independent-scaling pressure yet that would justify it.
Revisit only if a specific subsystem (e.g. the draw engine, or ticket
allocation under very high concurrency) demonstrably needs to scale or
deploy independently of the rest.

## Local development vs. production

Two compose files, deliberately not shared beyond the parts that are
identical (see `docker-compose.yml` / `docker-compose.prod.yml`):

| | Development (`docker-compose.yml`) | Production (`docker-compose.prod.yml`) |
|---|---|---|
| API server | `php -S` (built-in), source bind-mounted, edits apply instantly | `php-fpm`, code baked into the image, immutable |
| Web server | `next dev`, source bind-mounted, hot reload | `next start` equivalent — standalone Node server, immutable image |
| MySQL/Redis | Published to `127.0.0.1` on non-default ports, for local `mysql`/`redis-cli` access | No published ports at all |
| Secrets | Safe defaults baked into `docker-compose.yml` | Must be supplied via a root `.env` (never committed) |

## Environment variables

- `apps/web/.env.example` — frontend config (`NEXT_PUBLIC_API_URL`). Never
  put secrets in a `NEXT_PUBLIC_*` variable; it ends up in client-side JS.
- `apps/api/.env.example` — backend config (`APP_KEY`, `DB_*`, `REDIS_*`,
  etc.), standard Laravel.
- `.env.example` (repo root) — variables `docker-compose.prod.yml` reads
  directly (`DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD`/`DB_ROOT_PASSWORD`,
  `NEXT_PUBLIC_API_URL`). These are the single source of truth for the
  MySQL container's own credentials; the API container's `DB_*` values are
  set from the same root variables so they can never drift out of sync
  with what MySQL was actually initialized with.

For a real deployment: generate a real `APP_KEY` (`php artisan key:generate
--show`), set `APP_ENV=production`, `APP_DEBUG=false`, and use secrets
supplied by your deployment platform (see AWS direction below) — never
commit any of this. After the first migration run, create the first
admin with `php artisan admin:create-super` (P11.14) — see
[`docs/ADMIN_USERS_P11.md`](ADMIN_USERS_P11.md) "First SuperAdmin
bootstrap" — rather than any direct DB/tinker intervention.

## Health checks

- `GET /up` — Laravel's built-in liveness check.
- `GET /api/v1/health` — minimal liveness check, safe to expose publicly.
- `GET /api/v1/health/status` — reports `database`/`redis` reachability as
  `up`/`down` booleans only (no credentials, no raw exception text; `503`
  when degraded). Useful for container health checks / uptime monitors.

## Database schema (P2)

Core relational foundation is in place: `users`, `competitions`, `prizes`
(`Competition hasMany Prize`). Full reasoning in
[`docs/SCHEMA_DESIGN_P2.md`](SCHEMA_DESIGN_P2.md) — summary:

- **Money** is integer minor units (pence), never float — matches how
  payment providers bill (P6), avoids binary-float rounding errors.
- **Status** (`competitions.status`) is a plain string column backed by a
  PHP enum (`App\Enums\CompetitionStatus`), not a MySQL `ENUM` type — new
  statuses are a code change, not a migration.
- **IDs** stay auto-increment, matching the existing `users` convention;
  public URLs use `competitions.slug`, not the ID.
- **Deletion**: all three tables are soft-deleted (`deleted_at`) so
  history survives once Orders/Tickets/Draws (P5/P7/P9) reference them.
  `prizes.competition_id` restricts (rather than cascades) hard deletes.
- **Deferred on purpose**: no `categories`, `competition_rules`, or
  `competition_faqs` tables, and no per-prize images — see
  `docs/SCHEMA_DESIGN_P2.md` for why each was deferred rather than
  guessed at.

Competition/Prize now have a public read API — see the next section.

## Authentication (P3)

Cookie-session auth (Laravel Sanctum SPA mode) for the `/api/v1/auth/*`
endpoints — register, login, logout, email verification, password reset.
Full reasoning and endpoint table in
[`docs/AUTHENTICATION.md`](AUTHENTICATION.md). This is why
`/sanctum/*` is proxied by Nginx alongside `/api/*` (see the dev/prod
Nginx configs) and why `bootstrap/app.php` calls
`$middleware->statefulApi()`.

## Competition management (P4)

Public, read-only competition/prize browsing —
`GET /api/v1/competitions`, `GET /api/v1/competitions/{slug}`. Full
endpoint/query/visibility-rules reference in
[`docs/COMPETITIONS_API.md`](COMPETITIONS_API.md). Server Components
(the competition detail page's `generateMetadata`/data fetch) reach the
API via `API_INTERNAL_URL` (`http://nginx/api` in Docker) rather than
the browser-facing `NEXT_PUBLIC_API_URL` — see `src/lib/config.ts`.

## AWS direction (not provisioned — documentation only)

A V1 production footprint, deliberately not Kubernetes/ECS at this stage:

| Concern | Service |
|---|---|
| Compute | EC2 (running this same Docker Compose stack) or ECS Fargate once container orchestration is actually needed |
| Database | RDS for MySQL |
| Cache/Queue | ElastiCache for Redis |
| Media/uploads | S3 |
| CDN | CloudFront or Cloudflare in front of Nginx |
| Container registry | ECR (or GitHub Container Registry) |
| Secrets | AWS Secrets Manager / SSM Parameter Store, injected as container env vars — never baked into images |

Move to ECS/Fargate (or Kubernetes) only when a real, specific need appears
(e.g. independent autoscaling of the queue workers under ticket-purchase
load, or multi-region requirements) — not preemptively.

## Module map

Domain boundaries the future Laravel app should organize around (not yet
implemented — see [ROADMAP.md](ROADMAP.md)):

- **Competition** — listing, lifecycle (draft/live/ending-soon/sold-out/drawn), owns one Prize
- **Prize** — prize metadata, cash-alternative value
- **Entry / Ticket** — allocation, reservation, release; the highest-concurrency, highest-risk domain
- **Order / OrderItem** — purchase transaction grouping tickets
- **Payment** — provider integration, webhook handling, idempotency
- **Draw** — eligible-ticket snapshot, winner selection, audit log, idempotent execution
- **Winner / PrizeClaim** — claim workflow, delivery tracking
- **Notification** — email/SMS triggered by order/draw/winner events
- **Promotion** — discount codes
- **User** — accounts, auth (not yet implemented)

## What P1 deliberately did not build

No Competition/Ticket/Order/Payment/Draw/Winner models, migrations, or
controllers. No authentication. No payment gateway. No CORS config beyond
Laravel's default (not needed yet, given same-origin proxying — revisit if
a separate-origin client, e.g. a mobile app, needs the API directly). See
the P1 report's Known Limitations for specific rough edges to revisit.
