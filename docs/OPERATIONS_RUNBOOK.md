# RaffleGuru — Operations Runbook (P12.4)

Day-to-day operational workflows: admin accounts, payments, and the
competition/draw/winner lifecycle. This file was not named explicitly
in the P12.4 brief — it was created to house those three topics as one
operator-facing reference, rather than duplicating the detailed
per-phase design docs it links to below. For deployment/go-live, see
[`PRODUCTION_DEPLOYMENT.md`](PRODUCTION_DEPLOYMENT.md); for incidents,
see [`PRODUCTION_INCIDENT_RUNBOOK.md`](PRODUCTION_INCIDENT_RUNBOOK.md).

## Admin operations

Full design/invariants: [`ADMIN_USERS_P11.md`](ADMIN_USERS_P11.md).
Summary of what an operator actually does:

| Task | How | Who |
|---|---|---|
| Create the first SuperAdmin | `php artisan admin:create-super` (one-time, server/container only — see `PRODUCTION_DEPLOYMENT.md` §12) | Deploying operator |
| Create further admins | `/admin` → Admins resource → Create | SuperAdmin only |
| Change an admin's role | `/admin` → Admins → edit → role | SuperAdmin only |
| Activate/deactivate an admin | `/admin` → Admins → toggle | SuperAdmin only |
| Reset another admin's password | `/admin` → Admins → Reset password (sets a temporary password, forces change on next login) | SuperAdmin only |
| View audit history | `/admin` → Admins → Audit log tab | SuperAdmin only (ability `audit.view`) |

**SuperAdmin vs. Operations role:** per `AdminPolicy`, every admin-
management ability (`admin.manage`, `admin.view`, `audit.view`) is
SuperAdmin-only today — the Operations role exists as a distinct value
but currently has no admin-management abilities granted to it. (This
is a description of current `AdminPolicy` state, not a recommendation —
if Operations is meant to do more, that's a product decision, not
something to silently change here.)

**Password reset (self-service) does not exist for admins** — no email
reset flow, by design (`ADMIN_USERS_P11.md` "Passwords": *"No email
reset exists. The customer password broker is not used."*). The only
path for a locked-out admin is another SuperAdmin using "Reset
password" above. If every SuperAdmin is locked out simultaneously, the
only recovery path is direct database access (outside this runbook's
scope — treat as a security incident, see
`PRODUCTION_INCIDENT_RUNBOOK.md` item P).

**Forced password change:** after a reset, the admin can sign in but
can only reach the "change your password" screen until they complete
it. This is enforced server-side (`Admin::hasAbility()` returns `false`
for a forced-change admin), not just hidden in the UI.

**Session revocation:** deactivating an admin, resetting their
password, or forcing a password change all bump `session_version` —
every existing session for that admin is invalidated on its next
request, automatically. No separate "revoke sessions" action exists or
is needed.

**Last-SuperAdmin protection:** the service refuses any operation
(deactivate, demote, or an admin acting on themselves) that would leave
zero active SuperAdmins. This is enforced in
`AdminManagementService`, not the UI — hiding a button is never the
actual security boundary here.

**What is NOT implemented:** no admin-facing audit *export*, no
admin-to-admin messaging, no 2FA/MFA (session-cookie + password only).

## Payment operations

Full design: [`PAYMENTS_DESIGN_P6.md`](PAYMENTS_DESIGN_P6.md), API
reference: [`PAYMENTS_API.md`](PAYMENTS_API.md).

**Gateway configuration** — `apps/api/.env`:

```
PAYMENT_GATEWAY=razorpay
RAZORPAY_KEY_ID=<real key id>
RAZORPAY_KEY_SECRET=<real secret>
RAZORPAY_WEBHOOK_SECRET=<real webhook signing secret>
```

**Production requirement, enforced in code, not just convention:**
`PAYMENT_GATEWAY=fake` with `APP_ENV=production` makes
`App\Providers\PaymentServiceProvider` throw
`PaymentGatewayConfigurationException` at boot — the application
refuses to start at all, not just logs a warning. There is no way to
accidentally run the fake gateway in a process that also believes it's
production.

**Webhook configuration** — in the Razorpay dashboard, register:

```
https://<your-domain>/api/v1/payments/webhook/razorpay
```

This is the one unauthenticated public POST endpoint in the API —
authenticity comes entirely from the `X-Razorpay-Signature` header,
verified against `RAZORPAY_WEBHOOK_SECRET` inside the controller before
anything else happens (`PaymentWebhookController::razorpay()`). As of
P12.3 it's also rate-limited (`throttle:payment-webhook`, 120/min/IP —
see [`LOAD_AND_CONCURRENCY.md`](LOAD_AND_CONCURRENCY.md) "Rate
limiting") to bound abuse independent of signature validity.

**Idempotency:** every webhook delivery is deduplicated by
`sha256(raw body)` against a `unique(gateway, event_id)` database
constraint — a redelivered event is claimed by exactly one request and
every other delivery of the same event gets a `200 OK` "already
handled" response (never an error, so Razorpay has no reason to keep
retrying it). Verified under genuine concurrent delivery with real
forked-process MySQL tests
(`tests/Feature/Payment/PaymentConcurrencyTest.php`, P12.3).

**Payment failure handling:** a failed payment attempt never cancels
the order — the order returns to `pending` so the customer can retry
with a new payment attempt. See `PaymentService::markFailed()`.

**Refunds — do not claim automation that doesn't exist.**
`PaymentService::markRefunded()` is **passive and audit-only**: it
reflects a refund the gateway reports (via webhook), updating this
application's `payments`/`orders` status to match. **It does not
initiate a refund.** Initiating an actual refund happens in the
Razorpay dashboard (or via their API, called from outside this
codebase) — this application has no refund-initiation endpoint or
admin action. If a customer needs a refund, an operator must process it
directly with Razorpay; this application will reflect the outcome once
Razorpay's webhook reports it, not before.

**Operational verification after a payment incident:** check
`payments.status`, `payment_webhooks` (was the event received and
claimed?), and the order's own status — in that order. The `payments`
table is the source of truth for gateway-reported state; `orders` is
derived from it via `PaymentService`, never written to independently
for payment outcomes.

## Off-server backup operations (P12.6, optional)

Only applies if `BACKUP_OFFSITE_ENABLED=true` — see
`BACKUPS_DISASTER_RECOVERY.md` "Off-server backup (P12.6)" for the full
design. If offsite backup was never enabled, skip this section; local
backup operations are unaffected either way.

**Routine check** (periodic — not automated/alerted on by anything in
this repo): confirm the most recent `backup:run` actually reported
`Offsite upload verified:`, not just `Backup created:` — the two are
reported separately on purpose (see "Off-server backup (P12.6)"), and
only the second line means the off-server copy exists and was proven
intact.

```sh
docker compose -f docker-compose.prod.yml logs scheduler | grep -A1 "Backup created:"
```

**If offsite upload has been failing**: `backup:prune-offsite` reports
a loud warning (`newest_past_retention`) if the single most recent
remote backup is already older than the retention window — that is a
real operational problem (uploads have been failing for longer than
`BACKUP_OFFSITE_RETENTION_DAYS`), not routine output. Check:

```sh
docker compose -f docker-compose.prod.yml exec api php artisan backup:upload-offsite
```

against the most recent local backup, and read the error message —
common causes are an expired/revoked `BACKUP_OFFSITE_KEY`/
`BACKUP_OFFSITE_SECRET`, the bucket being deleted or renamed, or the
endpoint being unreachable (DNS, firewall, provider outage).

**Manual retention cleanup**, outside the daily cycle:

```sh
docker compose -f docker-compose.prod.yml exec api php artisan backup:prune-offsite
```

Safe to run at any time — `BackupOffsiteRetentionService` never
deletes the single most recent remote backup regardless of its age.

## TLS certificate operations (P12.5, Option C only)

Only applies if you're using `docker-compose.tls.yml` (TLS terminated
on this repo's own Nginx, see `PRODUCTION_DEPLOYMENT.md` §2 Option C).
If you're using Cloudflare or an external load balancer (Options A/B),
certificate operations happen entirely outside this repository — skip
this section.

**Routine check** (periodic, e.g. monthly — not automated/alerted on
by anything in this repo):

```sh
docker compose -f docker-compose.prod.yml -f docker-compose.tls.yml \
  exec certbot certbot certificates
```

Reports the certificate's expiry date. Renewal itself is automatic
(the `certbot` service renews on a 12-hour loop; `nginx` reloads daily
to pick up a renewed certificate — see `PRODUCTION_DEPLOYMENT.md` §2)
— this check is for confirming that automation is actually working,
not for performing the renewal yourself.

**If the certificate is unexpectedly close to expiry or renewal has
stopped working:**

```sh
# Check the certbot container's own logs for renewal failures.
docker compose -f docker-compose.prod.yml -f docker-compose.tls.yml logs certbot

# Force a renewal attempt immediately (bypasses the 30-day window
# check `certbot renew` normally applies).
docker compose -f docker-compose.prod.yml -f docker-compose.tls.yml exec certbot \
  certbot renew --cert-name raffleguru --force-renewal

docker compose -f docker-compose.prod.yml -f docker-compose.tls.yml exec nginx nginx -s reload
```

The most common real-world cause of a stuck renewal: port 80 stopped
being reachable from the public internet (DNS changed, a firewall rule
tightened, Cloudflare proxying everything including the ACME challenge
path) — Let's Encrypt's HTTP-01 challenge needs it. Confirm
`curl -I http://<your-domain>/.well-known/acme-challenge/anything`
returns a `404` from *this* Nginx (not a different host, not a
Cloudflare error page) before assuming the certbot container itself is
at fault.

## Competition / draw / winner operations

Full designs:
[`COMPETITION_LIFECYCLE_P8.md`](COMPETITION_LIFECYCLE_P8.md),
[`DRAW_ENGINE_P9.md`](DRAW_ENGINE_P9.md),
[`WINNER_CLAIM_P10.md`](WINNER_CLAIM_P10.md).

### Lifecycle at a glance

```
draft → scheduled → active → sold_out/closed → draw_pending → draw_in_progress → drawn → completed
```

Every arrow is enforced by `CompetitionLifecycleService`'s explicit
transition matrix — the *only* place `competitions.status` is ever
written. No status change happens directly in a controller, job, or
model.

### Who does what

| Step | What customer can do | What Operations can do | What SuperAdmin can do | Automated? |
|---|---|---|---|---|
| **Creation** | nothing | create/edit a draft competition in `/admin` | same, plus everything Operations can | Manual (admin action) |
| **draft → scheduled** | nothing | publish (schedules it, not yet public) | same | Manual (admin action, P11.9) |
| **scheduled → active** | nothing | nothing directly | nothing directly | **Automated** — `competition:lifecycle`, scheduled every minute (see `routes/console.php`), transitions at `starts_at` |
| **Ticket purchase** | buy tickets while `active` | — | — | Customer-initiated; allocation itself is automatic and concurrency-safe (`TicketAllocationService`) |
| **active → sold_out** | — (happens as a side effect of their own purchase reaching capacity) | — | — | **Automated** — triggered inline the instant capacity is reached, same transaction as the allocation that caused it |
| **active/sold_out → closed** | nothing | — | — | **Automated** — `competition:lifecycle`, at `ends_at` (or immediately if already sold out and past end time) |
| **closed → draw_pending** | nothing | — | — | **Automated** — `competition:lifecycle`, at `draw_at` |
| **draw_pending → draw_in_progress → drawn** | nothing | can manually trigger via `php artisan competition:draw {id}` if needed | same | **Automated primary path** (event-driven, `CompetitionDrawPending` → `DrawCompetitionJob`, picks up within seconds); a scheduled sweep (`competition:draw`, every 5 min) is a crash-recovery safety net, not the primary trigger |
| **Winner creation** | nothing | can manually trigger via `php artisan winners:reconcile` if needed | same | **Automated primary path** (event-driven, `CompetitionDrawn` → `CreateWinnerJob`); `winners:reconcile` (every 5 min) is the crash-recovery safety net |
| **Claim submission** | the one customer-initiated action in this whole domain — submit a claim while their winner status is `contacted` | — | — | Customer-initiated |
| **Claim review (verify/approve/reject)** | nothing | — | reviews and transitions claims | Manual (admin action) — no auto-approval exists |
| **Fulfilment (prize assignment, shipping, delivery)** | can view their own winner/claim/delivery status | starts fulfilment, assigns a prize if the competition has more than one, progresses delivery status | same | Manual throughout — **no courier API integration exists**; `delivery_method`/`tracking_reference` are operator-entered facts, never generated |
| **drawn → completed** | nothing | — | — | **Automated**, but only as a *consequence* of the prize actually reaching `delivered` — never fired automatically right after the draw itself |

### Manual operator commands (safety-net / recovery use only)

These exist for crash recovery, not as the primary way anything
happens — don't run them as routine operations unless you have a
specific reason (a stuck competition, a missed event):

```sh
docker compose -f docker-compose.prod.yml exec api php artisan competition:lifecycle
docker compose -f docker-compose.prod.yml exec api php artisan competition:draw [competition]
docker compose -f docker-compose.prod.yml exec api php artisan winners:reconcile
```

All three are idempotent and safe to run repeatedly — see each
service's own docblock (`CompetitionLifecycleService::transition()`,
`DrawService::run()`, `WinnerService::createFromDrawResult()`) for why.

### What is NOT implemented

No KYC provider integration, no courier/shipping API integration, no
automated prize-delivery notifications beyond what already exists —
all explicitly out of scope per `WINNER_CLAIM_P10.md` §19, and still
true as of P12.4.
