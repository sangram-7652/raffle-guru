# RaffleGuru — Production Smoke Test Checklist (P12.4)

Run after every production deploy. **Nothing here is destructive** —
real-money/real-payment checks are explicitly marked manual/controlled
and must never be run as an automated script against production.

## Infrastructure

- [ ] DNS resolves to the expected target (`dig <domain>`)
- [ ] HTTPS loads without a certificate warning (`curl -vI
      https://<domain>/`) — see `PRODUCTION_DEPLOYMENT.md` §2 for what
      "HTTPS" actually depends on in this stack
- [ ] Nginx responds (`docker compose -f docker-compose.prod.yml ps
      nginx` shows healthy)
- [ ] Frontend loads: `GET https://<domain>/` returns the homepage
- [ ] API responds: `GET https://<domain>/api/v1/health` → `200`
- [ ] MySQL reachable: `GET https://<domain>/api/v1/health/status` →
      `"database":"up"`
- [ ] Redis reachable: same response → `"redis":"up"`
- [ ] Queue worker running: `docker compose ps queue` shows up, no
      restart loop
- [ ] Scheduler running: `docker compose ps scheduler` shows up, no
      restart loop

## Customer-facing

- [ ] Homepage renders competition cards
- [ ] Competition listing page loads (`/competitions` or equivalent
      route) and filters/sorts work
- [ ] A competition detail page loads with correct prize/status info
- [ ] Registration: create a real test account, confirm the
      verification email path works (check `MAIL_MAILER` is a real
      mailer in production, not the dev `log` default)
- [ ] Login works with that test account
- [ ] Logout works
- [ ] Cart: add a competition entry to cart (if using a real account,
      not production customer data)
- [ ] Order creation reaches the payment step
- [ ] **Payment — controlled/manual only.** Use a small real
      transaction with a card you control, or Razorpay's own test-mode
      credentials if your Razorpay account supports a test mode
      alongside live mode. **Never** set `PAYMENT_GATEWAY=fake` in
      production to "test" this — see `OPERATIONS_RUNBOOK.md`. If a
      real small-value transaction isn't acceptable for this
      deployment, skip this check here and instead confirm it fully in
      staging (`STAGING_VERIFICATION.md`) before go-live, and mark this
      smoke-test item as "verified in staging" rather than skipped
      silently.
- [ ] After a successful payment, the ticket appears under the test
      account's entries

## Admin

- [ ] `/admin` loads the login screen
- [ ] SuperAdmin login works
- [ ] Dashboard loads with no errors
- [ ] Competitions resource: list loads, a competition's detail opens
- [ ] Orders resource: list loads
- [ ] Payments resource: list loads
- [ ] Entries resource: list loads
- [ ] Tickets are visible (via Entries/Orders detail view)
- [ ] Draws resource: list loads (if any competition has drawn)
- [ ] Winners resource: list loads
- [ ] Claims: visible via Winners detail
- [ ] Fulfilment: visible via Winners detail
- [ ] Customer (read-only) view loads
- [ ] Admin users resource: list loads, roster shows the expected
      SuperAdmin(s)

## Operational

- [ ] Logs are flowing: `docker compose logs --tail=20 api` shows
      recent activity (or at minimum, no startup errors)
- [ ] Sentry receiving events — if configured, trigger a deliberate,
      harmless test exception path (or check Sentry's own "test event"
      feature) rather than waiting for a real error
- [ ] A backup has run successfully today (or run one manually once:
      `php artisan backup:run`)
- [ ] Backup verification passes: `php artisan backup:verify`
- [ ] Queue is processing: dispatch a trivial test (e.g. re-run a
      harmless idempotent job) and confirm it completes
- [ ] Scheduler is executing on time: check `competition:lifecycle`
      actually ran within the last minute via its log output

## Sign-off

Record: deploy commit/tag, date/time, who ran the smoke test, and any
item that was skipped and why (e.g. "payment check deferred to
staging — see note above").
