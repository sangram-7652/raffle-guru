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
  manual Nginx restart. The one exception to "Nginx only proxies" is
  Filament's published static assets (CSS/JS/fonts, P11.15) — php-fpm's
  fastcgi has no equivalent of a dev server's "serve the real file if one
  exists" fallback, so those are baked directly into the production
  Nginx image at build time instead (see the `nginx` stage in
  `infrastructure/docker/php/Dockerfile`, built from the exact same
  `base` stage that publishes them, so the two can't drift apart).
- **P12.5**: every fastcgi-routed location (`/api`, `/sanctum`,
  `/admin`, Livewire) now explicitly forwards `X-Forwarded-For/-Proto/-
  Host` to php-fpm. `fastcgi_pass` forwards nothing by default — unlike
  the `proxy_pass` block for Next.js, which already set these via
  `proxy_set_header` — so before this, Laravel's API always saw
  Nginx's own address and scheme for every request regardless of the
  real client, silently making per-IP rate limiting
  (`RateLimiter::for('login'/'payment-webhook', ...)`) share one bucket
  across every client. `bootstrap/app.php`'s `trustProxies()` is
  correspondingly narrowed from `'*'` to the RFC1918 ranges Docker's
  own bridge networks assign from, since Nginx — the only container
  these headers can meaningfully come from, given nothing else in
  `docker-compose.prod.yml` publishes a port — always sits on one.
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

## Observability (P12.1)

- **Error tracking: Sentry**, via the official `sentry/sentry-laravel`
  package — already named as the intended choice in
  [`ROADMAP.md`](ROADMAP.md)'s P12 entry, has first-class Laravel 13
  support, and needs nothing custom-built: wired into
  `bootstrap/app.php`'s `withExceptions()` via
  `Sentry\Laravel\Integration::handles($exceptions)`, one more
  `reportable()` callback alongside Laravel's own — additive only,
  never touches `shouldRenderJsonWhen` or any HTTP response shape, and
  runs through Laravel's existing `$dontReport` rules, so expected
  `ValidationException`/`AuthorizationException`/404s etc. are never
  sent as if they were crashes.
- **Disabled by default everywhere except where explicitly
  configured.** `SENTRY_LARAVEL_DSN` (`apps/api/.env.example`) is empty
  by default; an empty DSN means the SDK makes zero network calls at
  all — not "configured to be quiet", genuinely inert. Only set a real
  DSN for the environment that should actually report (normally
  production only). `SENTRY_ENVIRONMENT` is deliberately left unset —
  the SDK falls back to `app()->environment()` on its own
  (`vendor/sentry/sentry-laravel`'s `ServiceProvider`), so there is no
  second place environment is configured.
- **No PII by default.** The published `config/sentry.php`'s
  `send_default_pii` defaults to `false` (`SENTRY_SEND_DEFAULT_PII`) —
  Sentry does not auto-attach request IPs/cookies/headers/user data.
  Not changed from the package's own safe default.
- **Log rotation: Docker, not Laravel's file rotation.** Production's
  `api`/`queue`/`scheduler` containers are immutable with no log
  volume (see "Local development vs. production" above) — a request
  to use Laravel's own `daily`/`monthly` file channels would rotate
  logs that are destroyed on every redeploy anyway, inside a container
  filesystem that was never meant to hold anything durable. Instead:
  production should set `LOG_CHANNEL=stderr` (`apps/api/.env.example`;
  already a stock Laravel channel, no new code), and every service in
  `docker-compose.prod.yml` — not just the Laravel ones — gets a
  `logging: *default-logging` block capping Docker's own `json-file`
  driver (otherwise unbounded by default) via `LOG_MAX_SIZE`/
  `LOG_MAX_FILES` (defaults: 10MB × 5 files per container). This is
  genuinely the one log rotation mechanism in play here, not an
  additional one layered on top of a file-based one.
- Production should also raise `LOG_LEVEL` above the dev default of
  `debug` (e.g. `warning`) to avoid high-volume, low-signal logs — a
  config/env change on deploy, not a code change.

## Backups & disaster recovery (P12.2)

Full reasoning, operational prerequisites, and the restore procedure in
[`docs/BACKUPS_DISASTER_RECOVERY.md`](BACKUPS_DISASTER_RECOVERY.md) —
summary:

- Daily logical MySQL backup (`backup:run`, scheduled 02:00 alongside
  the other entries in `routes/console.php`) to `BACKUP_PATH`, a Docker
  named volume (`backup-data`) — never the scheduler container's own
  ephemeral filesystem. 14-day retention by default
  (`BACKUP_RETENTION_DAYS`).
- `backup:verify` proves a backup is actually restorable by restoring it
  into a throwaway database and dropping it again — never the live
  database. Requires a one-time `CREATE`/`DROP DATABASE` grant scoped to
  this app's own verification-database naming pattern; see that doc.
- `backup:restore` is manual-only, requires an explicit `--database`
  with no default, and refuses to target the live database without an
  additional `--confirm-production-overwrite` flag.
- **Local backup only as of P12.2** — `backup-data` lives on the same
  host as `mysql-data`, so this does not yet protect against losing the
  server itself. Off-server/object-storage sync and a true
  disaster-recovery rehearsal are the documented next step, not yet
  implemented — see that doc's "Local backup vs. off-server backup vs.
  disaster recovery".

## Load & concurrency hardening (P12.3)

Full audit, new concurrency tests, and real baseline measurements in
[`docs/LOAD_AND_CONCURRENCY.md`](LOAD_AND_CONCURRENCY.md) — summary: the
P7-P11 row-locking/unique-constraint design was already correct under
real concurrent MySQL load (ticket allocation, payment/webhook
idempotency, lifecycle, draw, winner/claim/fulfilment, admin
SuperAdmin invariants) — this phase added the one real gap found (a
payment/webhook concurrency test), a rate limit on the previously-
unthrottled public webhook endpoint
(`throttle:payment-webhook`, 120/min/IP), and a k6-based load-test
foundation (`load-tests/k6/`) with a hard guard against ever targeting
production. No new database indexes or caching — neither was justified
by real evidence at this project's current data volume/traffic.

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
| Off-server backup (P12.6) | A SEPARATE S3 bucket + dedicated IAM credential from the media bucket above — see `BACKUPS_DISASTER_RECOVERY.md` "Off-server backup (P12.6)" for why these must never share a bucket or credential |
| CDN | CloudFront or Cloudflare in front of Nginx |
| Container registry | ECR (or GitHub Container Registry) |
| Secrets | AWS Secrets Manager / SSM Parameter Store, injected as container env vars — never baked into images |

Move to ECS/Fargate (or Kubernetes) only when a real, specific need appears
(e.g. independent autoscaling of the queue workers under ticket-purchase
load, or multi-region requirements) — not preemptively.

## Module map

Domain boundaries the Laravel app is organized around — **all
implemented** as of P12 (updated from this doc's original P1-era
version, which described these as future work; see
[`ROADMAP.md`](ROADMAP.md) for exactly which phase built each one):

- **Competition** — listing, lifecycle (draft/scheduled/active/sold_out/closed/draw_pending/draw_in_progress/drawn/completed), owns one or more Prizes (P2/P4/P8)
- **Prize** — prize metadata, cash-alternative value, stock tracking for fulfilment (P2/P10)
- **Entry / Ticket** — allocation, concurrency-safe capacity/per-user enforcement (P7)
- **Order / OrderItem** — purchase transaction grouping tickets (P5)
- **Payment** — Razorpay integration, webhook handling, idempotency (P6)
- **Draw** — eligible-ticket snapshot, winner selection, audit log, idempotent execution (P9)
- **Winner / WinnerClaim / PrizeDelivery** — claim workflow, delivery tracking, fulfilment (P10)
- **Admin** — staff accounts, roles, audit logging (P11)
- **User** — customer accounts, Sanctum auth (P3)

**Not implemented, and not currently planned:** Notification
(email/SMS beyond Laravel's own verification/password-reset mail) and
Promotion (discount codes) — both were speculative module-map entries
from the original P1 architecture sketch with no corresponding phase
built; treat them as out of scope unless explicitly requested, not as
upcoming work.

## Current implementation status (P12.4)

Every domain above is implemented and tested (SQLite + real-MySQL
concurrency suites, see
[`LOAD_AND_CONCURRENCY.md`](LOAD_AND_CONCURRENCY.md)). What remains
genuinely incomplete for a real production launch — not aspirational,
verified during this phase's audit:

- **TLS/HTTPS termination (P12.5)** — an optional `docker-compose.tls.yml`
  overlay (Nginx + Let's Encrypt via a `certbot` sidecar) now exists for
  operators terminating TLS directly on this stack's own Nginx; using
  Cloudflare or an external load balancer instead remains equally valid
  and needs none of it. Verified locally (nginx config test, a real TLS
  handshake and HTTP→HTTPS redirect against a self-signed certificate)
  — **not** verified against a real domain/real Let's Encrypt issuance
  or across an actual renewal cycle, since no real domain existed for
  this phase. See [`PRODUCTION_DEPLOYMENT.md`](PRODUCTION_DEPLOYMENT.md)
  §2 for the full picture, including what remains to be confirmed on an
  actual go-live.
- **Off-server backup (P12.6)** — an optional `BACKUP_OFFSITE_ENABLED`
  flag now exists: a dedicated S3-compatible bucket (AWS S3,
  DigitalOcean Spaces, Cloudflare R2, etc.), uploaded and verified
  (real download-and-rehash, not just an HTTP 200) after every
  `backup:run`. Disabled by default; implemented and tested against a
  real S3-compatible MinIO instance. **Not yet verified**: a real
  cloud provider, or a full disaster-recovery drill against a
  genuinely new host (the documented procedure has never been
  rehearsed) — see
  [`BACKUPS_DISASTER_RECOVERY.md`](BACKUPS_DISASTER_RECOVERY.md)
  "Off-server backup (P12.6)" and "What was and wasn't verified".
- **No deployment automation** — every production operation is a
  manual, documented command (`PRODUCTION_DEPLOYMENT.md`).
- No courier/shipping integration, no KYC provider, no automated
  refunds — see [`OPERATIONS_RUNBOOK.md`](OPERATIONS_RUNBOOK.md).

See [`GO_LIVE_CHECKLIST.md`](GO_LIVE_CHECKLIST.md) for the full,
explicit required/optional/not-implemented breakdown before an actual
go-live.
