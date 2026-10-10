# RaffleGuru — Production Deployment Runbook (P12.4)

This is the operator workflow for deploying RaffleGuru to a production
server. Every command here was verified against the actual repository
(`docker-compose.prod.yml`, `infrastructure/`, `apps/api/.env.example`,
`routes/`, `app/Console/Commands/`) as of this phase — not invented, not
aspirational. Where something in the repo is genuinely incomplete or
manual, this document says so explicitly rather than implying otherwise.

**Read this once, fully, before a first production deploy.** It assumes
no prior undocumented knowledge of how this specific project was built.

## Table of contents

1. [Prerequisites](#1-prerequisites)
2. [DNS, TLS/HTTPS, and the Cloudflare/Nginx relationship](#2-dns-tlshttps-and-the-cloudflarenginx-relationship)
3. [Firewall requirements](#3-firewall-requirements)
4. [Repository checkout](#4-repository-checkout)
5. [Environment configuration](#5-environment-configuration)
6. [Environment variable reference](#6-environment-variable-reference)
7. [Docker image build](#7-docker-image-build)
8. [Database migration](#8-database-migration)
9. [Storage / Filament assets](#9-storage--filament-assets)
10. [Starting the stack](#10-starting-the-stack)
11. [Health verification](#11-health-verification)
12. [SuperAdmin bootstrap](#12-superadmin-bootstrap)
13. [Smoke testing](#13-smoke-testing)
14. [Pre-deployment safety checklist](#14-pre-deployment-safety-checklist)
15. [Rollback](#15-rollback)

---

## 1. Prerequisites

**Server requirements** (not provisioned by this repo — operator-supplied):

- A Linux server (any distribution with a supported Docker Engine) with
  enough disk for: the `mysql-data`, `redis-data`, and `backup-data`
  Docker volumes, plus container images. No specific sizing has been
  measured — see [`docs/LOAD_AND_CONCURRENCY.md`](LOAD_AND_CONCURRENCY.md)
  for the honest state of load testing (read-path only, on a dev
  machine, not a production sizing exercise).
- A domain name pointed at this server (see §2).

**Docker requirements:**

- Docker Engine with Compose v2 (the `docker compose` subcommand, not
  the standalone `docker-compose` v1 binary — this repo's compose files
  use syntax Compose v2 expects).
- Enough free disk for image builds: the `api`/`queue`/`scheduler`
  images share one `php:8.5-fpm-alpine`-based build
  (`infrastructure/docker/php/Dockerfile`); `web` is a separate
  `node:24-alpine`-based build (`infrastructure/docker/web/Dockerfile`).

**What this repo does NOT provision** — genuinely true gaps, not
oversights to paper over:

- No Kubernetes, no managed container orchestration (deliberate — see
  "AWS direction" in
  [`PRODUCTION_ARCHITECTURE.md`](PRODUCTION_ARCHITECTURE.md)).
- No infrastructure-as-code (Terraform/CloudFormation/etc.) — the server
  itself, DNS, and TLS termination are all operator-provisioned outside
  this repository.
- No deployment script. This document **is** the deployment procedure —
  every step below is a command an operator runs by hand (or wires into
  their own CI/CD later); nothing in this repo automates it for you.

## 2. DNS, TLS/HTTPS, and the Cloudflare/Nginx relationship

**Three deployment options exist for TLS. Read this once, fully,
before choosing one.**

`infrastructure/nginx/prod.conf` has exactly one `server` block:
`listen 80;` — plain HTTP. `docker-compose.prod.yml`'s `nginx` service
publishes only `80:80`. By itself, that file still gives you plain HTTP
on port 80 only, unchanged since before P12.5 — this is deliberate, not
an oversight: TLS termination is optional application-stack behavior,
layered on top, not baked into the base production compose file (see
"AWS direction" in `PRODUCTION_ARCHITECTURE.md` treating
CloudFront/Cloudflare as an equally valid, operator-provisioned
alternative).

- **Option A — Cloudflare in front, "Full" or "Full (strict)" SSL
  mode.** Cloudflare terminates TLS at its edge and re-encrypts to your
  origin. Use Option C below to give the origin a real certificate for
  this (Cloudflare's "Full (strict)" requires one; "Full" tolerates a
  self-signed one but Option C gives you a real one anyway, so there's
  no reason to settle for self-signed). **Do not use "Flexible" mode**
  once Option C is in place — Flexible talks plain HTTP to the origin,
  and Option C's Nginx redirects plain HTTP to HTTPS, so the two
  together loop forever. If you specifically want Flexible (TLS only at
  Cloudflare's edge, never at the origin), skip Option C entirely and
  deploy `docker-compose.prod.yml` alone — this Nginx already speaks
  plain HTTP on port 80, exactly what Flexible mode expects.
- **Option B — a TLS-terminating reverse proxy/load balancer in front
  of this stack** (a cloud load balancer, a separate Caddy/Traefik
  instance, etc.) that forwards plain HTTP to this Nginx container.
  Deploy `docker-compose.prod.yml` alone, same as Flexible above — this
  repo needs no TLS code path for this option.
- **Option C — TLS terminated directly on this repo's own Nginx**,
  via the optional `docker-compose.tls.yml` overlay
  (`infrastructure/nginx/prod-tls.conf`) added in P12.5: a Let's
  Encrypt certificate issued/renewed by a `certbot` sidecar container,
  on port 443, with HTTP→HTTPS redirect on port 80. The one deployment
  option that is this repo's own responsibility end-to-end rather than
  an operator-supplied edge. See "Option C — bootstrap steps" below for
  the exact commands; nothing here is simulated or aspirational — the
  config was verified locally (nginx config test, a real TLS handshake,
  and a real HTTP→HTTPS redirect against a self-signed certificate; see
  the P12.5 PR description for exactly what was and wasn't verified)
  against this repository's actual files, not invented.

Options A and B need nothing from `docker-compose.tls.yml` at all —
don't add it unless you're doing Option C. Never run more than one of
these three at once (e.g. Option C's certbot overlay AND an external
load balancer both trying to terminate TLS) — pick exactly one.

Whichever option you pick, two things downstream depend on the browser
actually seeing HTTPS:

- `SESSION_SECURE_COOKIE` (`apps/api/.env`) **must be `true`** once the
  public-facing URL is HTTPS — the session cookie is then marked
  `Secure` and refuses to be sent over plain HTTP. **As of P12.5 this is
  enforced at boot, not just documented**: `AppServiceProvider::register()`
  throws a `RuntimeException` and refuses to boot at all if
  `APP_ENV=production` and `SESSION_SECURE_COOKIE` is not `true` — the
  same "fail loud at boot, not silently at login" pattern as the
  `PAYMENT_GATEWAY=fake` guard (see §6). This means you cannot run
  `APP_ENV=production` at all until HTTPS is genuinely in front of the
  server through one of the three options above — there is no
  "production but still plain HTTP for now" state anymore.
- `APP_URL`/`FRONTEND_URL`/`SANCTUM_STATEFUL_DOMAINS`/`NEXT_PUBLIC_API_URL`
  (see §6) must all use `https://`/the HTTPS host once TLS is live — a
  mismatch here breaks Sanctum's stateful-domain CSRF check.

**DNS requirements:** an A/AAAA record (or Cloudflare proxy record)
pointing your production domain at the server's public IP (or at
Cloudflare, if using Option A). No specific DNS provider is assumed by
this repo.

### Option C — bootstrap steps

Only relevant if you're terminating TLS on this repo's own Nginx.
Requires a domain's DNS already pointing at this server (port 80
reachable from the public internet — Let's Encrypt's HTTP-01 challenge
needs that to validate ownership) before you start.

```sh
# 0. Set your real domain for this session — used below, never stored
#    in any .env file (this repo does not invent or assume a domain).
export TLS_DOMAIN=your-real-domain.example
export TLS_CERTBOT_EMAIL=you@example.com   # certificate-expiry notices

# 1. One-time dummy certificate, so Nginx has *something* to load at
#    /etc/letsencrypt/live/raffleguru/ — it refuses to start at all
#    with a `listen 443 ssl;` block pointing at a cert that doesn't
#    exist yet, and the real cert (step 3) can't be issued until Nginx
#    is already up to serve the HTTP-01 challenge. Classic chicken/egg,
#    solved the standard way: a throwaway self-signed cert first.
docker compose -f docker-compose.prod.yml -f docker-compose.tls.yml run --rm \
  --entrypoint "mkdir -p /etc/letsencrypt/live/raffleguru && \
    openssl req -x509 -nodes -newkey rsa:2048 -days 1 \
    -keyout /etc/letsencrypt/live/raffleguru/privkey.pem \
    -out /etc/letsencrypt/live/raffleguru/fullchain.pem \
    -subj '/CN=localhost'" \
  certbot

# 2. Start the full stack with the TLS overlay. Nginx comes up on the
#    dummy cert from step 1; everything else (API/web/DB/Redis) is
#    unaffected by any of this.
docker compose -f docker-compose.prod.yml -f docker-compose.tls.yml up -d

# 3. Issue the real certificate via the HTTP-01 webroot challenge
#    (served by the Nginx that's already running from step 2, at
#    /.well-known/acme-challenge/ — see prod-tls.conf). --cert-name
#    pins the on-disk path to "raffleguru" regardless of the real
#    domain, which is what lets prod-tls.conf reference a fixed,
#    domain-independent certificate path instead of needing templating.
docker compose -f docker-compose.prod.yml -f docker-compose.tls.yml run --rm \
  --entrypoint "certbot certonly --webroot -w /var/www/certbot \
    --cert-name raffleguru -d \$TLS_DOMAIN --email \$TLS_CERTBOT_EMAIL \
    --agree-tos --no-eff-email --force-renewal" \
  certbot

# 4. Load the real certificate — a plain reload, no downtime.
docker compose -f docker-compose.prod.yml -f docker-compose.tls.yml exec nginx nginx -s reload

# 5. Verify.
curl -vI https://$TLS_DOMAIN/ 2>&1 | grep -i "< HTTP\|subject\|issuer"
```

**Renewal** is automatic from here: the `certbot` service in
`docker-compose.tls.yml` runs `certbot renew` every 12 hours in a loop
(a no-op until the certificate is within Let's Encrypt's ~30-day
renewal window), and the `nginx` service reloads itself once a day —
comfortably inside that window, so a renewed certificate is always
picked up well before the old one expires. Confirm renewal is actually
working with `docker compose -f docker-compose.prod.yml -f
docker-compose.tls.yml exec certbot certbot certificates` (reports
expiry) periodically, e.g. as part of §13's smoke test cadence — this
has not been observed across a real 90-day cycle as part of this phase
(no real domain/time to do so), so treat the mechanism as implemented
and locally verified (config test, real TLS handshake, real redirect
against a self-signed cert), not as "proven over a real renewal."

**If you need to stop using Option C** (e.g. switching to Cloudflare):
`docker compose -f docker-compose.prod.yml -f docker-compose.tls.yml down`
the overlay, then run `docker-compose.prod.yml` alone — Nginx goes back
to plain HTTP on port 80 only, nothing else in the stack is affected.

## 3. Firewall requirements

Not configured by this repo (no firewall rules are provisioned here).
Operator-level guidance based on what `docker-compose.prod.yml` actually
exposes:

- **Must be reachable from the internet:** port 80 always (`nginx` is
  the only service with a published port in `docker-compose.prod.yml`
  on its own) — **plus port 443 if you're using Option C**
  (`docker-compose.tls.yml`, see §2), which publishes it. Options A/B
  need only 80 at this server; their TLS-terminating edge is the public
  443 endpoint instead.
- **Must NOT be reachable from the internet:** everything else. `mysql`
  and `redis` publish no ports at all in the production compose file,
  and `docker-compose.tls.yml`'s `certbot` service publishes none
  either — by construction, not by firewall rule, none of them are
  reachable outside the Docker network. If your hosting provider's
  default is "all ports open," add a host-level firewall
  (ufw/iptables/security group) that only allows 80 (+443 for Option C)
  inbound, SSH for operator access, and nothing else — this repo does
  not do that for you.

## 4. Repository checkout

```sh
git clone https://github.com/<org>/raffle-guru.git
cd raffle-guru
git checkout main   # or the specific tagged/verified commit — see §14
```

## 5. Environment configuration

Three separate `.env` files, each copied from its own `.env.example`
and never committed real:

```sh
cp .env.example .env                      # root — docker-compose.prod.yml reads this directly
cp apps/api/.env.example apps/api/.env    # Laravel config
```

(`apps/web` needs no `.env` in production — `NEXT_PUBLIC_API_URL` is
supplied as a build/runtime environment variable via the root `.env`
and `docker-compose.prod.yml`'s `web` service, not a `.env.local` file,
which is dev-only.)

Fill in every value in both files for real — see §6 for the full
reference. At minimum, before going further:

- Generate a real `APP_KEY`:
  ```sh
  docker run --rm -v "$PWD/apps/api:/app" -w /app php:8.5-cli php artisan key:generate --show
  ```
  (prints a `base64:...` value — paste it into `apps/api/.env`'s
  `APP_KEY=`; running this in a throwaway container avoids needing PHP
  installed on the host just for this one command).
- Set `APP_ENV=production`, `APP_DEBUG=false` in `apps/api/.env` — **never
  deploy with `APP_DEBUG=true`**: Laravel's debug mode renders stack
  traces (including environment values) directly in HTTP error
  responses.
- Set real `DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD`/`DB_ROOT_PASSWORD`
  in the root `.env` — these are the single source of truth for the
  `mysql` container's own credentials (see "Environment variables" in
  `PRODUCTION_ARCHITECTURE.md`); `apps/api/.env`'s own `DB_*` values
  should match (the `api`/`queue`/`scheduler` services' `environment:`
  block in `docker-compose.prod.yml` already pulls `DB_DATABASE`/
  `DB_USERNAME`/`DB_PASSWORD` from the root `.env` for you, but set them
  consistently in both files to avoid confusion).
- Set `PAYMENT_GATEWAY=razorpay` (or leave unset — that's the default)
  and real `RAZORPAY_KEY_ID`/`RAZORPAY_KEY_SECRET`/`RAZORPAY_WEBHOOK_SECRET`
  — see §6 for the hard production guard against `PAYMENT_GATEWAY=fake`.

## 6. Environment variable reference

Values marked **secret** must never be committed, logged, or pasted
into a chat/ticket in plaintext. This table documents variable names
and required behavior — not actual secret values.

### Root `.env` (read by `docker-compose.prod.yml` directly)

| Variable | Required | Secret | Production value/behavior | Consumed by |
|---|---|---|---|---|
| `DB_DATABASE` | yes | no | Real database name | `mysql` service init, `api`/`queue`/`scheduler` |
| `DB_USERNAME` | yes | no | Real app DB username | same |
| `DB_PASSWORD` | yes | **yes** | Real app DB password | same |
| `DB_ROOT_PASSWORD` | yes | **yes** | Real MySQL root password | `mysql` service init/healthcheck |
| `NEXT_PUBLIC_API_URL` | yes | no | `https://<your-domain>/api` — browser-facing, ends up in client JS | `web` service build/runtime |
| `LOG_MAX_SIZE` | no | no | Default `10m` — per-container Docker log cap | `x-logging` anchor, all services |
| `LOG_MAX_FILES` | no | no | Default `5` | same |

### `apps/api/.env` (Laravel)

| Variable | Required | Secret | Production value/behavior | Consumed by |
|---|---|---|---|---|
| `APP_NAME` | no | no | Display name only | Laravel |
| `APP_ENV` | yes | no | **`production`** | Laravel, `PaymentServiceProvider`'s guard (see below) |
| `APP_KEY` | yes | **yes** | Generated once, never rotated casually (rotating invalidates all existing sessions/encrypted data) | Laravel encrypter, session, Sanctum |
| `APP_DEBUG` | yes | no | **`false`** — never `true` in production | Laravel error rendering |
| `APP_URL` | yes | no | `https://<your-domain>` | Laravel URL generation |
| `FRONTEND_URL` | yes | no | Same origin as `APP_URL` (Nginx serves both) | CORS defaults, password-reset link generation |
| `CORS_ALLOWED_ORIGINS` | no | no | Explicit comma-separated list; defaults to `FRONTEND_URL` | `config/cors.php` |
| `LOG_CHANNEL` | yes | no | **`stderr`** (not the dev default `stack`) — see "Observability" in `PRODUCTION_ARCHITECTURE.md` for why | Laravel logging |
| `LOG_LEVEL` | no | no | `warning` recommended (dev default `debug` is noisy) | Laravel logging |
| `DB_CONNECTION` | yes | no | `mysql` | Laravel database |
| `DB_HOST` | yes | no | Overridden to `mysql` by `docker-compose.prod.yml`'s `environment:` block regardless of this file | same |
| `DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD` | yes | password is **secret** | Must match the root `.env` values | same |
| `SESSION_DRIVER` | yes | no | `redis` | Laravel session |
| `SESSION_SECURE_COOKIE` | yes | no | **`true`**, required — **enforced at boot as of P12.5**: `AppServiceProvider` throws and refuses to boot if `APP_ENV=production` and this isn't `true` (see §2) | Session cookie, `AppServiceProvider::register()` |
| `SANCTUM_STATEFUL_DOMAINS` | yes | no | The exact browser-facing host(s), e.g. `yourdomain.com` (no protocol) | `config/sanctum.php` |
| `QUEUE_CONNECTION` | yes | no | `redis` | Laravel queue |
| `CACHE_STORE` | yes | no | `redis` | Laravel cache |
| `REDIS_HOST` | yes | no | Overridden to `redis` by `docker-compose.prod.yml` | Laravel Redis |
| `MAIL_MAILER` | yes | no | A real mailer (dev default is `log`, which never actually sends) | Laravel mail — email verification, password reset |
| `PAYMENT_GATEWAY` | yes | no | `razorpay` (default) — **must never be `fake`**, see below | `App\Providers\PaymentServiceProvider` |
| `RAZORPAY_KEY_ID` | yes (if using Razorpay) | no (public id) | Real Razorpay key id | `RazorpayPaymentGateway` |
| `RAZORPAY_KEY_SECRET` | yes | **yes** | Real Razorpay secret | same |
| `RAZORPAY_WEBHOOK_SECRET` | yes | **yes** | Real Razorpay webhook signing secret | `PaymentWebhookController` signature check |
| `SENTRY_LARAVEL_DSN` | recommended | **yes** | A real Sentry DSN — empty disables the SDK entirely (no network calls at all) | `bootstrap/app.php`'s `Integration::handles()` |
| `SENTRY_SEND_DEFAULT_PII` | no | no | Leave default `false` — do not enable without a deliberate decision to send request/user data to Sentry | `config/sentry.php` |
| `BACKUP_PATH` | yes | no | **Must** be set to the mounted volume path, `/var/backups/raffleguru` (matches the `backup-data` volume mount in `docker-compose.prod.yml`) — never left on the container's own ephemeral filesystem | `config/backup.php` |
| `BACKUP_RETENTION_DAYS` | no | no | Default `14` — documented assumption, see `BACKUPS_DISASTER_RECOVERY.md` "Retention" | same |
| `BACKUP_OFFSITE_ENABLED` | no | no | `false` by default — local backup is fully functional either way. See `BACKUPS_DISASTER_RECOVERY.md` "Off-server backup (P12.6)" for the full picture before enabling, including what hasn't been verified against a real cloud provider yet | `config/backup.php` |
| `BACKUP_OFFSITE_KEY` / `BACKUP_OFFSITE_SECRET` | yes if offsite enabled | **yes** | A DEDICATED credential for the backup bucket only — never reuse `AWS_*` | `config/filesystems.php`'s `backups` disk |
| `BACKUP_OFFSITE_BUCKET` / `BACKUP_OFFSITE_ENDPOINT` / `BACKUP_OFFSITE_REGION` | yes if offsite enabled (endpoint only for non-AWS providers) | no | Any S3-compatible provider — AWS S3, DigitalOcean Spaces, Cloudflare R2, etc. | same |
| `BACKUP_OFFSITE_RETENTION_DAYS` | no | no | Default `30` | `config/backup.php` |

**The `PAYMENT_GATEWAY=fake` production guard** — not just documentation,
an enforced code path: `App\Providers\PaymentServiceProvider::register()`
throws `PaymentGatewayConfigurationException` and refuses to boot the
application at all if `APP_ENV=production` and `PAYMENT_GATEWAY=fake`
are both true. This fails the whole request/process, not silently — if
you see this exception in production, it means `PAYMENT_GATEWAY` is
misconfigured, not that something is broken.

Variables not listed above (Sentry's ~45 other tracing/breadcrumb
toggles in `config/sentry.php`, standard Laravel mail/queue/cache
options) have safe stock defaults and don't need a production-specific
value — see the config file itself if you need one of them.

## 7. Docker image build

```sh
docker compose -f docker-compose.prod.yml build
```

What this actually does, verified against
`infrastructure/docker/php/Dockerfile` and
`infrastructure/docker/web/Dockerfile`:

- `api`/`queue`/`scheduler` share one image (`target: prod`): installs
  PHP 8.5-fpm-alpine + `mariadb-client`/`mariadb-connector-c` (needed by
  `backup:run`/`backup:verify`/`backup:restore`, see
  `BACKUPS_DISASTER_RECOVERY.md`) + required PHP extensions, runs
  `composer install --no-dev`, and — **automatically, at build time, not
  a manual step** — runs `php artisan filament:assets` so the admin
  panel's CSS/JS/fonts are baked into the image before it's ever
  deployed.
- `nginx` is built from the **same** Dockerfile (`target: nginx`), which
  `COPY`s those same Filament asset files from the `base` stage into an
  `nginx:1.27-alpine` image — this is what makes `/css/filament/` etc.
  servable directly by Nginx without a round-trip to PHP (see
  `infrastructure/nginx/prod.conf`).
- `web` (`target: prod`): Node 24-alpine, `npm run build` (Next.js
  standalone output), runs as `node server.js` — no dev dependencies in
  the final image.

## 8. Database migration

**Not automated** — no `RUN php artisan migrate` exists in the
Dockerfile, and no container entrypoint runs it at startup. This is a
deliberate, separate, operator-run step:

```sh
# 1. Back up first — see §15 and BACKUPS_DISASTER_RECOVERY.md. On a
#    genuinely first deploy (empty database) there's nothing to back up
#    yet; skip this sub-step only in that specific case.
docker compose -f docker-compose.prod.yml exec api php artisan backup:run

# 2. Check what would run, before running it.
docker compose -f docker-compose.prod.yml exec api php artisan migrate:status

# 3. Run it.
docker compose -f docker-compose.prod.yml exec api php artisan migrate --force
```

(`--force` is required because Laravel prompts for confirmation when
`APP_ENV=production`; this is Laravel's own built-in production
safety prompt, not something this repo added.)

**On migration rollback and its real limits** — see §15 for the full
treatment. The short version: every migration in this repo has a real
`down()` method (verified — none are empty/no-op), so `migrate:rollback`
is mechanically possible for all of them. That is **not** the same as
"always safe." Rolling back a migration that dropped/altered a column
after real production data has been written using the new shape can
lose that data — `down()` reverses the *schema*, not the *consequences*
of real traffic that already happened against it. Treat
`migrate:rollback` as a schema tool for a deploy that hasn't taken real
traffic yet, not a general-purpose undo button once it has.

## 9. Storage / Filament assets

- **Filament assets**: already handled — see §7, baked in at image
  build time. No manual step.
- **`storage:link`**: standard Laravel setup step
  (`php artisan storage:link`, symlinks `public/storage` →
  `storage/app/public`). **Honest note verified against the actual
  schema**: as of this phase, the application stores no file through
  that disk — `competitions.images` is a JSON array of plain URL
  strings (see `database/migrations/..._create_competitions_table.php`),
  not uploaded files. Running `storage:link` today has no functional
  effect on anything currently live; it's included here for Laravel-
  standard completeness and so nothing breaks the day a feature
  actually does upload through `storage/app/public`. Harmless either
  way:
  ```sh
  docker compose -f docker-compose.prod.yml exec api php artisan storage:link
  ```
- **Cache**: optional production optimization, safe to run after every
  deploy once config/routes are stable:
  ```sh
  docker compose -f docker-compose.prod.yml exec api php artisan config:cache
  docker compose -f docker-compose.prod.yml exec api php artisan route:cache
  ```
  Remember to `php artisan config:clear`/`route:clear` (or just
  redeploy the image, which starts fresh) if you change `.env` after
  caching — a cached config silently ignores new `.env` values.
- **Never run `php artisan db:seed` in production** — `DatabaseSeeder`
  creates fake users/competitions and is explicitly documented in its
  own docblock as development-only sample data.

## 10. Starting the stack

```sh
docker compose -f docker-compose.prod.yml up -d
```

Service startup order is enforced by `depends_on`/healthchecks in
`docker-compose.prod.yml`: `mysql`/`redis` must report healthy before
`api`/`queue`/`scheduler` start; `nginx` waits for `api` healthy and
`web` started.

- **Queue worker**: `php artisan queue:work --tries=3 --sleep=3` — a
  long-running container (`queue` service), not a cron job.
- **Scheduler**: `php artisan schedule:work` — also a long-running
  container (`scheduler` service), not a system cron entry. It owns:
  `competition:lifecycle` (every minute), `competition:draw` (every 5
  minutes, crash-recovery sweep), `winners:reconcile` (every 5 minutes,
  same reasoning), `backup:run` (daily at 02:00) — see
  `apps/api/routes/console.php` for the authoritative schedule.

## 11. Health verification

| Endpoint | Purpose | Expected |
|---|---|---|
| `GET /up` | Laravel's built-in liveness check | 200 |
| `GET /api/v1/health` | Minimal liveness, safe to expose publicly | `{"status":"ok",...}`, 200 |
| `GET /api/v1/health/status` | Reports MySQL/Redis reachability as `up`/`down` booleans only — no credentials, no raw exception text | `{"status":"ok","dependencies":{"database":"up","redis":"up"}}`, 200; `503` with `"status":"degraded"` if either dependency is down |

```sh
curl -fsS https://<your-domain>/api/v1/health/status
```

Also check container-level health directly:

```sh
docker compose -f docker-compose.prod.yml ps
```

All services should show `healthy` (or, for `web`/`scheduler`/`queue`,
which have no `healthcheck:` block defined, simply `running` with no
restart loop).

## 12. SuperAdmin bootstrap

Run once, after migrations, before anyone needs to use `/admin`:

```sh
docker compose -f docker-compose.prod.yml exec api php artisan admin:create-super
```

- Prompts for **name**, **email**, and **password** (entered twice,
  hidden input, never echoed to the terminal or logged).
- Refuses immediately — before asking anything — if a SuperAdmin
  already exists; safe to run again by accident, it's a no-op refusal
  the second time.
- Full invariants and concurrency behavior:
  [`ADMIN_USERS_P11.md`](ADMIN_USERS_P11.md) "First SuperAdmin
  bootstrap".

The admin panel is at `https://<your-domain>/admin` (Filament,
`app/Providers/Filament/AdminPanelProvider.php` — `path('admin')`).

## 13. Smoke testing

See [`PRODUCTION_SMOKE_TEST.md`](PRODUCTION_SMOKE_TEST.md) for the full
checklist. At minimum before declaring a deploy successful: health
endpoints (§11), homepage loads, competition listing loads, admin login
works.

## 14. Pre-deployment safety checklist

None of these are automated/enforced by CI today — this is a manual
operator checklist, stated as such, not a claim of automation:

- [ ] Git state is clean (`git status` on the server/deploy runner shows
      nothing uncommitted) and you know exactly which commit/tag you're
      deploying.
- [ ] CI is green on that exact commit (`gh pr checks` /
      `.github/workflows/ci.yml`'s three jobs — Frontend, Backend,
      Backend MySQL concurrency).
- [ ] Every required environment variable from §6 is set to a real
      production value in both `.env` files on the server.
- [ ] `PAYMENT_GATEWAY` is `razorpay` (or unset), **never** `fake` — see
      §6; this one is also enforced at runtime, but verify it before
      deploying anyway.
- [ ] Database connectivity: `docker compose -f docker-compose.prod.yml
      exec api php artisan tinker --execute="DB::connection()->getPdo();"`
      doesn't throw.
- [ ] Redis connectivity: same idea —
      `php artisan tinker --execute="Redis::connection()->ping();"`.
- [ ] A recent, **verified** backup exists — `backup:run` succeeded AND
      `backup:verify` succeeded (see
      [`BACKUPS_DISASTER_RECOVERY.md`](BACKUPS_DISASTER_RECOVERY.md)).
      A backup that was taken but never verified is not the same
      guarantee.
- [ ] If `BACKUP_OFFSITE_ENABLED=true`: the most recent `backup:run`'s
      output included `Offsite upload verified:`, not just
      `Backup created:` — a local-only success is not the same
      guarantee as an off-server one (see
      [`BACKUPS_DISASTER_RECOVERY.md`](BACKUPS_DISASTER_RECOVERY.md)
      "Off-server backup (P12.6)").
- [ ] Sufficient disk space for the deploy (new image layers, a new
      backup, MySQL's own growth) — check with `df -h` on the host; no
      specific threshold is enforced by this repo.
- [ ] `docker compose -f docker-compose.prod.yml ps` on the *current*
      (pre-deploy) stack shows every service healthy, so you know you're
      not deploying on top of an already-broken system.
- [ ] If using Option C (§2): TLS certificate is valid and not close to
      expiry — `docker compose -f docker-compose.prod.yml -f
      docker-compose.tls.yml exec certbot certbot certificates`.
- [ ] `SESSION_SECURE_COOKIE=true` in `apps/api/.env` — also enforced at
      boot (§2, §6) as of P12.5, so a production container that's
      actually running already proves this, but check it before
      deploying rather than finding out from a crash-looping container.
- [ ] DNS resolves correctly to the target server/Cloudflare.

## 15. Rollback

**Application rollback**, **database rollback**, and **full disaster
recovery** are three different operations with three different risk
profiles — never treat them as interchangeable.

### Application rollback (code only, no data change)

The lowest-risk rollback: redeploy a previous, known-good image/commit
without touching the database.

```sh
git checkout <previous-known-good-tag-or-commit>
docker compose -f docker-compose.prod.yml build
docker compose -f docker-compose.prod.yml up -d
```

Safe **only if** no migration between the old and new commit changed
the database schema in a way the old code can't handle (e.g. the new
code added a NOT NULL column the old code never writes — rolling the
code back while that column exists and has no default is a real risk).
Check `database/migrations/` between the two commits before assuming
this is safe.

### Docker image rollback

If you tag images (`docker compose -f docker-compose.prod.yml build` +
push to a registry, which this repo's compose files don't do for you —
add image tagging/registry push yourself if you want this), rolling
back is pulling the previous tag and recreating containers from it. This
repo's compose files build locally by default (`build:` not `image:`),
so "image rollback" here is equivalent to "application rollback" above
(rebuild from the old commit) unless you've separately set up a
registry — not assumed.

### Database rollback

Two sub-options, genuinely different:

1. **`migrate:rollback`** — reverses the most recent migration batch's
   schema changes via each migration's `down()`. Mechanically available
   for every migration in this repo (verified — see §8). **Not safe**
   once real data depends on the new shape; see §8's warning.
   ```sh
   docker compose -f docker-compose.prod.yml exec api php artisan migrate:rollback --force
   ```
2. **Restore from backup** — the actual safe option once real data is
   involved. See [`BACKUPS_DISASTER_RECOVERY.md`](BACKUPS_DISASTER_RECOVERY.md)
   "Restore procedure" for the full `backup:restore` workflow,
   including its production-overwrite safety flags
   (`--database=<target>`, `--confirm-production-overwrite`). **This
   loses any writes made after the backup was taken** — that gap is
   this project's current RPO (~24h, see that doc's "RPO/RTO"), not
   something this rollback step can close.

### Full disaster recovery

Only relevant if the *server itself* is lost, not just a bad deploy.
**As of P12.2/P12.3, backups were local-only** —
`backup-data` lives on the same Docker host as `mysql-data`, so losing
the server lost the backup too.

**As of P12.6, an optional off-server copy exists** —
`BACKUP_OFFSITE_ENABLED=true` uploads the same backup to a separate
S3-compatible bucket, verified by a real download-and-rehash, not just
a local backup. Disabled by default; if it was never enabled on the
server that was just lost, the P12.2/P12.3 situation above still
applies. See
[`BACKUPS_DISASTER_RECOVERY.md`](BACKUPS_DISASTER_RECOVERY.md)
"Off-server backup (P12.6)" and "Disaster recovery procedure" for the
full workflow — and its "What was and wasn't verified" section before
assuming more than has actually been tested: the offsite mechanism is
verified against a real S3-compatible test instance, but the full
recovery procedure has never been rehearsed against a genuinely new
host, and no real cloud provider has been exercised yet.

### Post-rollback verification

Whichever rollback you performed, re-run §11 (health verification) and
the relevant parts of §13 (smoke test) before considering the incident
closed.
