# RaffleGuru — Staging Verification Sequence (P12.4)

Why this document exists: P12.3's load testing deliberately only
covered read paths, against the *shared local development* database —
see [`LOAD_AND_CONCURRENCY.md`](LOAD_AND_CONCURRENCY.md) "What was
deliberately NOT load-tested in this session." Write-path load testing
(orders, payment, ticket allocation, draw) and a full end-to-end
rehearsal both require an **explicitly isolated staging environment**
with its own disposable database — not production, not the shared dev
machine. This document is that sequence.

**Staging must be its own environment** — its own `mysql-data`
volume, its own domain/subdomain, ideally its own server or at minimum
fully isolated containers. Never point staging's `DB_*` at the
production database "just to save time."

## 1. Deployment

- [ ] Follow [`PRODUCTION_DEPLOYMENT.md`](PRODUCTION_DEPLOYMENT.md) §1-11
      exactly, targeting the staging domain/server.
- [ ] Confirm `APP_ENV` — staging can reasonably be `production` (to
      exercise the real `PaymentServiceProvider` guard and
      `APP_DEBUG=false` behavior) or a dedicated `staging` value if you
      want Sentry/logs to distinguish the two; either way, confirm it's
      **not** the literal value your monitoring treats as real
      production traffic.
- [ ] `PAYMENT_GATEWAY` — use Razorpay's own test-mode credentials if
      available for this account, so payment testing is realistic
      without moving real money. `PAYMENT_GATEWAY=fake` is acceptable
      here specifically *because* `APP_ENV` is not the literal
      `production` value the guard checks — confirm this is actually
      true for your staging `APP_ENV` before relying on it.

## 2. Database migration

- [ ] `php artisan migrate --force` against the staging database
      (empty on first setup — no backup-first step needed unlike real
      production).
- [ ] `php artisan migrate:status` confirms everything applied.

## 3. Admin bootstrap

- [ ] `php artisan admin:create-super` — create a staging-only
      SuperAdmin with credentials that are **not** reused anywhere
      else.

## 4. Customer registration/login

- [ ] Register a new test customer account.
- [ ] Confirm the verification email (use a real mailbox reachable from
      staging, or a mail-catching tool like Mailpit/Mailtrap if
      configured for this environment — `MAIL_MAILER` should not
      silently be the dev `log` driver here if you actually want to see
      the email).
- [ ] Log in, log out, log back in.

## 5. Competition flow

- [ ] Create a competition via `/admin` with a short enough
      schedule/duration that you can observe lifecycle automation
      within the test session (minutes, not days) — or manually drive
      `competition:lifecycle`/`competition:draw` as described in
      `OPERATIONS_RUNBOOK.md`.
- [ ] Confirm it becomes visible/purchasable on the public listing at
      `active`.

## 6. Order flow

- [ ] Add entries to cart, create an order.
- [ ] Confirm price/quantity integrity (server re-derives from the
      database, never trusts the client — verify by trying to tamper
      with a request payload and confirming the server rejects/ignores
      it).

## 7. Payment

- [ ] Complete a payment using Razorpay test-mode credentials.
- [ ] Confirm `payments.status` reaches `Paid` and `orders.status`
      follows.
- [ ] Deliberately test a **failed** payment (Razorpay test mode
      usually has a documented way to simulate this) and confirm the
      order returns to `pending`, not cancelled.

## 8. Webhook

- [ ] Confirm the webhook actually fires from Razorpay test mode and
      is received (`payment_webhooks` row created, `processed_at`
      set).
- [ ] **Concurrency check** (this is what staging is specifically for
      — see the rationale above): send the same webhook payload twice
      in quick succession (Razorpay's dashboard may offer a "resend"
      feature for this, or replay the raw body yourself with the
      correct signature) and confirm it's claimed exactly once —
      cross-check against
      `tests/Feature/Payment/PaymentConcurrencyTest.php`'s assertions,
      now observed live rather than only in a forked-process test.

## 9. Ticket allocation

- [ ] Confirm tickets appear under the customer's entries immediately
      after payment succeeds.
- [ ] Confirm ticket numbers are sequential and within the
      competition's `max_entries`.

## 10. Draw

- [ ] Let the competition reach `draw_pending` (via its schedule or by
      advancing it manually per `OPERATIONS_RUNBOOK.md`).
- [ ] Confirm it reaches `drawn` with exactly one `draw_results` row.

## 11. Winner

- [ ] Confirm a `winners` row was created automatically
      (`pending_contact`).
- [ ] Advance it to `contacted` via the admin panel.

## 12. Claim

- [ ] As the winning test customer, submit a claim.
- [ ] As an admin, review and approve it.

## 13. Fulfilment

- [ ] Start fulfilment, assign a prize if the competition has more than
      one.
- [ ] Progress the delivery through to `delivered`.
- [ ] Confirm the winner reaches `completed` and the competition itself
      reaches `completed`.

## 14. Backup

- [ ] `php artisan backup:run` succeeds.
- [ ] Confirm the artifact appears in staging's own `BACKUP_PATH`.

## 15. Restore verification

- [ ] `php artisan backup:verify` succeeds — this is a real restore
      into a throwaway database (see
      [`BACKUPS_DISASTER_RECOVERY.md`](BACKUPS_DISASTER_RECOVERY.md)
      "Verification"), genuinely exercised here, not simulated.
- [ ] **If you want to rehearse a full restore** (recommended at least
      once before relying on this for real): `php artisan backup:restore
      <file> --database=<a new scratch db> --force`, then inspect it.
      Never point `backup:restore` at staging's *own* live database
      without `--confirm-production-overwrite` and a reason to actually
      want that.

## 16. Load test (write paths)

This is the specific gap `LOAD_AND_CONCURRENCY.md` documented as
deferred — close it here, now that there's a disposable database:

- [ ] Extend `load-tests/k6/read_paths.js` (or add a sibling script)
      covering cart/order creation and the payment callback, following
      the same production-safety-guard pattern (refuse unless
      `BASE_URL` is staging, via `ALLOW_NON_LOCAL_TARGET=STAGING_CONFIRMED`
      or a staging-specific equivalent check).
- [ ] Run it against staging specifically:
      ```sh
      docker run --rm --network host \
        -e BASE_URL=https://staging.<your-domain> \
        -e ALLOW_NON_LOCAL_TARGET=STAGING_CONFIRMED \
        -v "$PWD/load-tests/k6:/scripts" \
        grafana/k6 run /scripts/<write-path-script>.js
      ```
- [ ] Record real p50/p95/p99 and error rate — update
      `LOAD_AND_CONCURRENCY.md` with genuine write-path numbers once
      you have them, replacing the "deliberately not measured" note
      there with real evidence (not before).

## Sign-off

Record: staging environment identity, date, commit/tag tested, and the
outcome of every section above (pass/fail/skipped-with-reason). A
staging run that skips section 16 is still useful for correctness
verification — just say so, don't imply load testing happened if it
didn't.
