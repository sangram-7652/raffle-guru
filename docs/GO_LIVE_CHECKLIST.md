# RaffleGuru — Go-Live Checklist (P12.4)

Every item is tagged:

- 🔴 **REQUIRED BEFORE PRODUCTION** — going live without this is a real
  risk, not a theoretical one.
- 🟡 **OPTIONAL / POST-GO-LIVE** — genuinely fine to defer.
- ⚠️ **NOT IMPLEMENTED** — this repository does not do this today. Not
  a checkbox to tick; a gap to either close first or accept
  consciously, with eyes open.

This checklist does not certify the project "production-ready" by
itself — it's a point-in-time tool. Re-verify anything environment-
specific (DNS, TLS, secrets) for every new environment you deploy to.

## A. Code

- [ ] 🔴 Deploying from a known commit/tag on `main`, not an arbitrary
      branch
- [ ] 🔴 `git status` clean on whatever checks out the deploy

## B. CI

- [ ] 🔴 All three CI jobs green on the deployed commit (Frontend,
      Backend, Backend MySQL concurrency — `.github/workflows/ci.yml`)
- [ ] 🟡 CI artifact/image build wired into an actual CD pipeline — not
      implemented; deploys are manual (`PRODUCTION_DEPLOYMENT.md`)

## C. Security

- [ ] 🔴 `APP_DEBUG=false`
- [ ] 🔴 `PAYMENT_GATEWAY` is `razorpay`, never `fake` (also enforced
      at runtime — see `OPERATIONS_RUNBOOK.md`)
- [ ] 🔴 No secret present in any committed file (see "Security/secret
      scan" in the P12.4 final report — this phase's own docs were
      scanned; re-scan anything new before every future doc change)
- [ ] 🔴 `SESSION_SECURE_COOKIE=true` — **required once HTTPS is live
      in front** (see item J below). As of P12.5 this is enforced at
      boot, not just documented: the app refuses to start at all under
      `APP_ENV=production` if this isn't `true`
      (`AppServiceProvider::register()`), so there is no longer a
      "production but HTTPS isn't ready yet" interim state — HTTPS
      (one of the three options in item J) must exist before you can
      set `APP_ENV=production` at all.
- [ ] ⚠️ No 2FA/MFA on admin accounts — session cookie + password only
      (`ADMIN_USERS_P11.md`)
- [ ] 🟡 Rotate `RAZORPAY_*`/`APP_KEY`/DB passwords on a periodic
      schedule — no rotation policy or tooling exists; a manual process
      decision, not implemented here

## D. Environment

- [ ] 🔴 Every variable in `PRODUCTION_DEPLOYMENT.md` §6 set to a real
      value in both `.env` files
- [ ] 🔴 `APP_URL`/`FRONTEND_URL`/`SANCTUM_STATEFUL_DOMAINS`/
      `NEXT_PUBLIC_API_URL` all consistent with the actual production
      domain and protocol (https)

## E. Database

- [ ] 🔴 Migrations run (`migrate --force`) and `migrate:status`
      confirms clean
- [ ] 🔴 `db:seed` has **not** been run against this database (dev-only
      fake data)
- [ ] 🟡 Query/index performance validated at realistic data volume —
      P12.3's audit found current indexing adequate for current (near-
      zero) data volume (`LOAD_AND_CONCURRENCY.md`); re-verify once
      real volume exists, don't assume it still holds forever

## F. Redis

- [ ] 🔴 Reachable from `api`/`queue` (health check confirms this)
- [ ] 🟡 Persistence/eviction policy tuned for production — currently
      stock `redis:7-alpine` defaults; not reviewed in P12.3's audit
      beyond "stock usage, no custom caching" — fine for current scope,
      revisit if Redis starts holding anything beyond
      cache/session/queue

## G. Queue

- [ ] 🔴 `queue` container running and healthy
- [ ] 🔴 `failed_jobs` empty (or every entry explained) before calling
      the deploy successful

## H. Scheduler

- [ ] 🔴 `scheduler` container running and healthy
- [ ] 🔴 Confirm `competition:lifecycle` actually executed within the
      last minute (check its log output) — this is what drives every
      time-based competition transition

## I. Nginx

- [ ] 🔴 `nginx -t` passes, container healthy
- [ ] 🔴 `/admin`, `/api`, `/` all route correctly (not all three
      falling into one catch-all)

## J. HTTPS

- [ ] 🔴 Pick exactly one of the three options in
      `PRODUCTION_DEPLOYMENT.md` §2 and confirm it's live before
      go-live — **never more than one at once** (e.g. an external load
      balancer AND this repo's own certbot overlay both terminating
      TLS):
      - **Option A** — Cloudflare in front (Full/Full strict mode)
      - **Option B** — an external TLS-terminating reverse proxy/load
        balancer
      - **Option C** — this repo's own `docker-compose.tls.yml` overlay
        (Nginx + certbot/Let's Encrypt), added in P12.5
- [ ] 🔴 Certificate valid, auto-renewal in place:
      - Option A/B: not this repo's concern — verify with whatever
        terminates TLS there.
      - Option C: automatic (`certbot` service renews on a loop, Nginx
        reloads daily — see `PRODUCTION_DEPLOYMENT.md` §2). **Verified
        in this phase**: nginx config test, a real TLS handshake, and a
        real HTTP→HTTPS redirect against a locally-generated self-signed
        certificate. **Not verified**: a full real-domain Let's Encrypt
        issuance or an actual 90-day renewal cycle — no real domain was
        available to this phase. Confirm both manually against your
        real domain before relying on this for go-live.
- [ ] 🔴 `SESSION_SECURE_COOKIE=true` — see item C; enforced at boot as
      of P12.5.

## K. DNS

- [ ] 🔴 Production domain resolves to the correct target
- [ ] 🟡 A staging subdomain also configured, pointed at a genuinely
      separate environment (see `STAGING_VERIFICATION.md`)

## L. Filament

- [ ] 🔴 `/admin` loads, assets (CSS/JS/fonts) render correctly —
      baked into the image at build time, verify it actually happened
      (`PRODUCTION_DEPLOYMENT.md` §7)
- [ ] 🔴 At least one active SuperAdmin exists
      (`admin:create-super` run)

## M. Payment

- [ ] 🔴 Real Razorpay credentials configured, not test-mode, for the
      actual go-live
- [ ] 🔴 Webhook URL registered in the Razorpay dashboard and verified
      receiving events (a real or controlled test transaction — see
      `PRODUCTION_SMOKE_TEST.md`)
- [ ] 🔴 `throttle:payment-webhook` active (it's code-level, not
      config — verify by checking the route has the middleware, not by
      assuming)
- [ ] ⚠️ No automated refund capability — refunds are processed
      directly in the Razorpay dashboard; this app only reflects the
      outcome (`OPERATIONS_RUNBOOK.md`)

## N. Backups

- [ ] 🔴 `BACKUP_PATH` set to the mounted volume path, not the
      container's ephemeral filesystem
- [ ] 🔴 First `backup:run` succeeds, `backup:verify` confirms it's
      restorable
- [ ] 🔴 The one-time `CREATE`/`DROP DATABASE` grant for the backup-
      verification user is in place (`BACKUPS_DISASTER_RECOVERY.md`
      "Verification") — without it, `backup:verify` fails loudly, which
      is correct behavior, but confirm it's set up before relying on
      verification passing
- [ ] ⚠️ **OFF-SERVER DR: NOT YET IMPLEMENTED.** Backups are local to
      the same Docker host as the live database. Losing the server
      loses both. This is a real, open production infrastructure
      requirement, not a theoretical one — see
      `BACKUPS_DISASTER_RECOVERY.md` "Local backup vs. off-server
      backup vs. disaster recovery." **Decide explicitly** whether
      going live without this is acceptable for this deployment, rather
      than discovering it during an actual server-loss incident.

## O. Monitoring

- [ ] 🔴 `SENTRY_LARAVEL_DSN` set to a real DSN for this environment
      (empty = no error tracking at all)
- [ ] 🟡 Uptime monitoring (external, e.g. a third-party pinger hitting
      `/api/v1/health/status`) — not provisioned by this repo; genuinely
      an operator/infra choice
- [ ] 🟡 Log aggregation beyond `docker compose logs`/Docker's own
      json-file driver — not implemented; acceptable at current scale,
      revisit if operating multiple hosts

## P. Admin

- [ ] 🔴 SuperAdmin account created, credentials stored securely
      (password manager, not plaintext in a ticket/chat)
- [ ] 🔴 Confirm the "at least one active SuperAdmin" invariant holds
      (it's enforced by the service regardless, but verify at least
      one actually exists post-deploy)

## Q. Customer

- [ ] 🔴 Registration, login, logout all verified (see
      `PRODUCTION_SMOKE_TEST.md`)
- [ ] 🔴 Email verification/password reset emails actually deliver
      (`MAIL_MAILER` is a real mailer, not the dev `log` driver)

## R. Competition

- [ ] 🔴 At least one real competition created and verified to appear
      correctly on the public listing/detail pages

## S. Draw

- [ ] 🟡 A full draw cycle rehearsed in staging before the first real
      one in production (`STAGING_VERIFICATION.md` §10) — strongly
      recommended, not strictly blocking if the design is otherwise
      trusted via its existing test coverage

## T. Winner/claims

- [ ] 🟡 Claim submission/review flow rehearsed in staging

## U. Fulfilment

- [ ] 🟡 Fulfilment flow rehearsed in staging — remember this is
      entirely manual (no courier integration), so this is really
      "confirm the admin UI/workflow is usable," not "confirm
      automation works"

## V. Smoke test

- [ ] 🔴 Full `PRODUCTION_SMOKE_TEST.md` checklist run and signed off
      immediately after the production deploy

## W. Rollback readiness

- [ ] 🔴 You know, before deploying, exactly which previous commit/tag
      you'd roll back to if this deploy goes wrong
- [ ] 🔴 A verified backup exists from immediately before this deploy
- [ ] 🔴 You've read `PRODUCTION_DEPLOYMENT.md` §15 and understand the
      difference between application rollback, database rollback, and
      full disaster recovery **before** you need one of them, not
      during an incident
