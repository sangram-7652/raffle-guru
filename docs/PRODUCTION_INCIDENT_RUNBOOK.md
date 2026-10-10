# RaffleGuru — Production Incident Runbook (P12.4)

For each incident: symptoms, first checks, safe commands, logs to
inspect, recovery action, verification, and the point at which you stop
and escalate rather than keep trying things. **Every "safe command"
below is read-only or additive** (restarting a service, reading logs) —
anything genuinely destructive is called out explicitly as such, with a
warning, never presented as a routine step.

All `docker compose` commands below assume
`-f docker-compose.prod.yml`, omitted for brevity.

## A. Website unavailable (frontend)

**Symptoms:** browser shows a connection error or Nginx's own error page
for the main site; API endpoints may still work.

1. **First checks:** `docker compose ps web nginx` — is `web` running
   and healthy? `curl -I http://localhost/` from the server itself
   (bypasses DNS/TLS to isolate whether it's an infra-in-front problem
   vs. the app itself).
2. **Logs:** `docker compose logs --tail=200 web`, `docker compose logs
   --tail=200 nginx`.
3. **Recovery:**
   ```sh
   docker compose restart web
   ```
   If it keeps crashing, check for an OOM (`docker compose logs web |
   grep -i memory`) or a bad deploy (did the last `web` image build
   actually succeed?).
4. **Verify:** `GET /` returns the homepage; §11 health checks in
   `PRODUCTION_DEPLOYMENT.md`.
5. **Escalate if:** restarting doesn't help within a couple of attempts,
   or the previous deploy is the suspected cause — see rollback
   (`PRODUCTION_DEPLOYMENT.md` §15).

## B. API unavailable

**Symptoms:** `/api/*` requests fail/timeout; site may still render
(cached/static parts) but no data loads.

1. **First checks:** `docker compose ps api` — healthy? The healthcheck
   is a raw TCP connect to php-fpm's port 9000 (see
   `docker-compose.prod.yml`), so "healthy" only means php-fpm is
   accepting connections, not that the application logic inside is
   error-free.
2. **Logs:** `docker compose logs --tail=200 api`. Also check Sentry if
   `SENTRY_LARAVEL_DSN` is configured — unhandled exceptions report
   there (see `PRODUCTION_ARCHITECTURE.md` "Observability").
3. **Recovery:** `docker compose restart api`. If that doesn't help,
   check whether MySQL/Redis are actually reachable (incidents C/D
   below) — most "API unavailable" incidents are actually a downstream
   dependency failure, not the API container itself.
4. **Verify:** `GET /api/v1/health/status` returns 200 with both
   dependencies `up`.
5. **Escalate if:** dependencies are fine but the API still errors —
   likely an application bug from the last deploy; consider rollback.

## C. MySQL unavailable

**Symptoms:** `/api/v1/health/status` reports `"database":"down"`;
everything that touches the database fails.

1. **First checks:** `docker compose ps mysql`. `docker compose logs
   --tail=200 mysql` — look for a crash, an out-of-disk message, or a
   corrupted-table message at startup.
2. **Safe commands:**
   ```sh
   docker compose exec mysql mysqladmin ping -h localhost -uroot -p"$DB_ROOT_PASSWORD"
   ```
3. **Recovery:** `docker compose restart mysql`. If the container won't
   start at all, check `df -h` on the host first — a full disk is a
   common real cause and restarting won't fix it (see incident N).
4. **⚠️ Do NOT** run anything that recreates the `mysql-data` volume
   (`docker compose down -v`, `docker volume rm`) to "fix" a MySQL
   problem — that destroys the database. If MySQL genuinely won't
   recover, this is a database-restore situation
   (`BACKUPS_DISASTER_RECOVERY.md` "Restore procedure"), not a
   quick-restart one.
5. **Verify:** `/api/v1/health/status` reports `"database":"up"`.
6. **Escalate if:** the container won't start and the cause isn't
   obviously disk space — this risks data loss; don't guess further,
   get a second pair of eyes before touching the data volume.

## D. Redis unavailable

**Symptoms:** `/api/v1/health/status` reports `"redis":"down"`;
sessions, cache, and the queue all depend on it — expect logins to
fail and jobs to stop processing.

1. **First checks:** `docker compose ps redis`, `docker compose logs
   --tail=200 redis`.
2. **Recovery:** `docker compose restart redis`. Redis data here is
   cache/session/queue state — not a system of record (see
   `LOAD_AND_CONCURRENCY.md` "Redis/cache audit") — losing it loses
   active sessions (customers must log in again) and in-flight queued
   jobs (which are retried work, not lost business state — the
   underlying domain data they'd act on is in MySQL, unaffected).
3. **Verify:** `/api/v1/health/status` reports `"redis":"up"`; try
   logging in as a test user.
4. **Escalate if:** it won't come back up after a restart and the logs
   don't show an obvious cause.

## E. Queue stopped

**Symptoms:** orders stay unpaid-looking longer than expected,
allocations/draws/winner-creation don't happen even though the
triggering event fired — check `failed_jobs` and the queue container's
own status before assuming something is actually broken (a brief queue
restart is normal and self-recovers).

1. **First checks:** `docker compose ps queue`.
   ```sh
   docker compose exec api php artisan queue:failed
   ```
2. **Logs:** `docker compose logs --tail=200 queue`.
3. **Recovery:** `docker compose restart queue`. For jobs already in
   `failed_jobs` that are worth retrying (check *why* they failed
   first — see incident K, "never retry business-rule failures as if
   they were transient"):
   ```sh
   docker compose exec api php artisan queue:retry <id>
   # or, after confirming it's safe:
   docker compose exec api php artisan queue:retry all
   ```
4. **Verify:** new jobs dispatched after the restart actually process
   (check a test order's ticket allocation, or watch `queue:failed`
   stay empty for new activity).
5. **Escalate if:** jobs keep failing for the same reason after a
   retry — that's a real bug or data problem, not a queue-health issue.

## F. Scheduler stopped

**Symptoms:** competitions stop transitioning on time
(`scheduled→active`, `active→closed`, `closed→draw_pending`); the
crash-recovery sweeps (`competition:draw`, `winners:reconcile`) stop
running.

1. **First checks:** `docker compose ps scheduler`.
2. **Logs:** `docker compose logs --tail=200 scheduler`.
3. **Recovery:** `docker compose restart scheduler`.
4. **Manual stopgap while it's down** — run the affected commands by
   hand (safe, idempotent — see `OPERATIONS_RUNBOOK.md`):
   ```sh
   docker compose exec api php artisan competition:lifecycle
   docker compose exec api php artisan competition:draw
   docker compose exec api php artisan winners:reconcile
   ```
5. **Verify:** a competition that should have transitioned actually
   did, after running the command manually or once the scheduler is
   back.
6. **Escalate if:** it keeps crashing — check for the same root cause
   as incident B (dependency failures surface here too, since this
   container runs the same application code).

## G. Nginx failure

**Symptoms:** the whole site (frontend and API both) is unreachable,
even though `web`/`api` containers themselves are healthy.

1. **First checks:** `docker compose ps nginx`, `docker compose logs
   --tail=200 nginx`. Common cause: a bad `infrastructure/nginx/prod.conf`
   (or `prod-tls.conf`, if using Option C — see `PRODUCTION_DEPLOYMENT.md`
   §2) edit that doesn't pass `nginx -t`.
2. **Safe command:**
   ```sh
   docker compose exec nginx nginx -t
   ```
3. **Recovery:** if the config is bad, revert the file and
   `docker compose restart nginx` (the config is bind-mounted, see
   `docker-compose.prod.yml` — no rebuild needed, just a restart once
   the file on disk is fixed).
4. **Verify:** `curl -I http://localhost/` and `/api/v1/health` both
   respond.
5. **Escalate if:** `nginx -t` passes but it still won't serve traffic
   — check the host's own firewall/port-80-in-use situation.

## H. TLS/DNS failure

**Symptoms:** browsers show a certificate error, or the domain doesn't
resolve at all.

**Important context (P12.5):** whether this repository terminates TLS
itself depends on which of the three options in
`PRODUCTION_DEPLOYMENT.md` §2 you're running. Options A (Cloudflare) and
B (external load balancer) put TLS entirely outside this repo's
control — a cert error there means a problem in whatever sits in front,
not in anything `docker-compose.prod.yml` controls directly. **Option
C** (the optional `docker-compose.tls.yml` overlay) makes this repo
responsible for its own certificate via a `certbot` sidecar — see
`OPERATIONS_RUNBOOK.md` "TLS certificate operations" for that case
specifically before assuming it's an external-provider incident.

1. **First checks:** `dig <your-domain>` / `nslookup <your-domain>` —
   does it resolve to the expected IP (or Cloudflare)? `curl -vI
   https://<your-domain>/` — read the actual TLS error.
2. **Recovery depends entirely on which TLS option you chose** (see
   `PRODUCTION_DEPLOYMENT.md` §2):
   - Options A/B: Cloudflare dashboard, or your load balancer's own
     cert management — this repo has no commands for this layer.
   - Option C: see `OPERATIONS_RUNBOOK.md` "TLS certificate operations"
     — check `docker compose -f docker-compose.prod.yml -f
     docker-compose.tls.yml exec certbot certbot certificates` for
     expiry/status, then that same section's renewal-failure steps
     (most common cause: port 80's ACME challenge path became
     unreachable from the public internet).
3. **Verify:** `curl -vI https://<your-domain>/` shows a valid chain.
4. **Escalate if:** DNS is correct, and either (Options A/B) you don't
   control the TLS-terminating layer directly, or (Option C) a forced
   renewal attempt still fails after confirming port 80 is reachable —
   at that point it's worth checking Let's Encrypt's own status page
   before continuing to retry.

## I. Payment gateway failure

**Symptoms:** customers report payments failing to initiate or
Razorpay's dashboard shows an outage; webhooks stop arriving.

1. **First checks:** is Razorpay itself reporting an incident (their
   status page)? Check this app's own logs for the actual error:
   ```sh
   docker compose logs --tail=200 api | grep -i payment
   ```
2. **Logs:** Sentry (if configured) for the actual exception from
   `RazorpayPaymentGateway`.
3. **Recovery:** nothing to restart on this side if the gateway itself
   is down — this is an upstream dependency. Orders stay `pending`
   (never silently marked paid) — see `PaymentService::markFailed()`:
   a failed/uninitiated payment never cancels the order, so customers
   can retry once the gateway recovers.
4. **Verify:** a test payment (small real amount, or the fake gateway
   in a non-production environment — never `PAYMENT_GATEWAY=fake` in
   production, see `OPERATIONS_RUNBOOK.md`) completes successfully.
5. **Escalate if:** the gateway is up but payments still fail from this
   app specifically — check `RAZORPAY_KEY_ID`/`RAZORPAY_KEY_SECRET`
   haven't rotated/expired on Razorpay's side without updating
   `apps/api/.env`.

## J. Duplicate webhook symptoms

**Symptoms:** you suspect a payment was processed twice, or see
unexpectedly many rows in `payment_webhooks` for what should be one
event.

**This should not actually be possible** —
`unique(gateway, event_id)` on `payment_webhooks` plus
`PaymentService::markPaid()`'s own row-lock-and-idempotent-check (two
independent layers, see `OPERATIONS_RUNBOOK.md` "Idempotency") — but
verify rather than assume:

1. **First checks:**
   ```sql
   SELECT gateway, event_id, COUNT(*) FROM payment_webhooks
     GROUP BY gateway, event_id HAVING COUNT(*) > 1;
   ```
   This should always return zero rows — the unique constraint makes a
   true duplicate row impossible. If you see customer-visible
   duplicate-charge symptoms, the actual cause is almost certainly on
   Razorpay's side (two genuinely separate payment attempts, not one
   event processed twice) — check the Razorpay dashboard for the
   payment ids involved.
2. **Verify the Payment/Order state directly:**
   ```sql
   SELECT id, status, paid_at FROM payments WHERE order_id = <id>;
   SELECT id, status FROM orders WHERE id = <id>;
   ```
3. **Escalate if:** you find an actual duplicate row in
   `payment_webhooks` — that would mean the unique constraint itself
   failed, which would be a serious, unexpected finding worth treating
   as a security/data-integrity incident (item P), not a routine fix.

## K. Draw failure

**Symptoms:** a competition is stuck in `draw_in_progress` (or
`draw_pending` longer than expected).

1. **First checks:**
   ```sh
   docker compose exec api php artisan tinker --execute="
     echo App\Models\Competition::find(<id>)->status->value;"
   ```
   Check `draws`/`draw_audit_logs` for that competition for a
   `failure_reason`.
2. **Recovery:** `DrawService` already self-recovers a crashed attempt
   after a 15-minute stale lease (see `DRAW_ENGINE_P9.md`) — if it's
   been less than 15 minutes, it may just still be running normally.
   Past that:
   ```sh
   docker compose exec api php artisan competition:draw <id-or-slug>
   ```
   Safe to run — idempotent, picks up a stale attempt or no-ops if
   another worker is still genuinely active.
3. **If the failure reason is "no eligible tickets"** — this is a
   deterministic domain failure (`DrawException`), already recorded on
   the `draws` row. **Do not retry blindly** — understand why there are
   no eligible tickets (e.g. every order for that competition was
   refunded) before deciding what, if anything, to do; there is no
   automated recovery for this case because it usually reflects a real
   business situation, not a transient failure.
4. **Verify:** the competition reaches `drawn` with exactly one
   `draw_results` row (enforced by a unique constraint regardless).
5. **Escalate if:** `competition:draw` doesn't resolve it and the
   `draw_audit_logs` reason isn't a recognizable domain exception.

## L. Failed job backlog

**Symptoms:** `php artisan queue:failed` shows a growing list.

1. **First checks:** group by exception type/job class — are these all
   the same known deterministic failure (business-rule exceptions
   already `$this->fail()` themselves deliberately, see
   `LOAD_AND_CONCURRENCY.md` "Queue/job safety" — those are *expected*
   to land here, not a bug), or something new/transient?
2. **Recovery:** for genuinely transient failures (a MySQL blip during
   incident C, for example), once the underlying cause is fixed:
   ```sh
   docker compose exec api php artisan queue:retry all
   ```
   For deterministic business-rule failures, do not retry — they'll
   fail identically again; they need an operator/product decision, not
   a requeue.
3. **Verify:** the backlog stops growing for new activity; retried jobs
   that should now succeed actually do.
4. **Escalate if:** the backlog is growing from a cause you can't
   identify from the exception class/message.

## M. Backup failure

**Symptoms:** `backup:run` exits non-zero, or
`docs/BACKUPS_DISASTER_RECOVERY.md`'s expected daily backup isn't
appearing.

1. **First checks:** `docker compose logs --tail=200 scheduler | grep
   backup` (the scheduler container is what actually runs `backup:run`,
   see `routes/console.php`). The command itself fails loudly — see
   `DatabaseBackupService`: a failed/truncated dump is never reported
   as a success.
2. **Common causes, verified against the actual implementation:** disk
   full on the `backup-data` volume; `mariadb-dump`/`mariadb-connector-c`
   missing from the image (shouldn't happen — baked into the Dockerfile,
   but check after an image change); wrong DB credentials after a
   rotation.
3. **Recovery:**
   ```sh
   docker compose exec api php artisan backup:run
   ```
   run by hand to see the real error directly.
4. **Verify:** `backup:verify` passes on the resulting file.
5. **Escalate if:** backups keep failing — you are accumulating backup
   gap risk every day this goes unresolved; treat as higher priority
   than its "just infrastructure" framing might suggest.

**P12.6, if `BACKUP_OFFSITE_ENABLED=true`:** `backup:run` can fail for
the offsite half specifically while the local backup above still
succeeded — its output distinguishes `Backup created:` (local, fine)
from a missing `Offsite upload verified:` line / an `Offsite upload
failed` error (the actual problem). Do NOT treat this the same as the
local failure above:

1. **First checks:** the error message from `backup:run`'s own output
   (or `backup:upload-offsite` run by hand against the most recent
   local backup, for the same error without retaking the backup) —
   common causes are an expired/revoked `BACKUP_OFFSITE_KEY`/
   `BACKUP_OFFSITE_SECRET`, the bucket deleted/renamed, or the
   provider endpoint unreachable. See `OPERATIONS_RUNBOOK.md`
   "Off-server backup operations" for the exact commands.
2. **This is lower urgency than a local backup failure** (item M
   above) IF local backups are still succeeding — you still have a
   same-server recovery option. It becomes HIGH urgency the moment
   `backup:prune-offsite` reports `newest_past_retention: true`
   (surfaced as a loud warning, not hidden) — that means the single
   remaining off-server copy is already old, and the server-loss
   protection P12.6 exists for is no longer real until this is fixed.
3. **Escalate if:** the endpoint is reachable (confirm with a plain
   `curl` to `BACKUP_OFFSITE_ENDPOINT`) but authentication keeps
   failing — likely a credential rotation that wasn't propagated to
   `apps/api/.env`, not a provider-side outage.

## N. Disk full

**Symptoms:** MySQL/writes failing, container restarts failing, `df -h`
shows 100%.

1. **First checks:** `df -h` on the host. `du -sh
   /var/lib/docker/volumes/*` to find which volume grew — `mysql-data`,
   `backup-data` (check retention is actually pruning — see
   `BackupRetentionService`), or Docker's own log files (capped by
   `LOG_MAX_SIZE`/`LOG_MAX_FILES`, but verify that cap is actually in
   effect — `docker-compose.prod.yml`'s `x-logging` anchor).
2. **⚠️ Do NOT** delete files inside `mysql-data` directly — that's a
   live database's files, not a safe cleanup target.
3. **Safe recovery:**
   - Prune old backups manually if retention has somehow not run:
     ```sh
     docker compose exec api php artisan backup:prune
     ```
   - Clear old, already-rotated Docker logs if `LOG_MAX_FILES` wasn't
     applied for some reason:
     ```sh
     docker system df   # see what's actually using space first
     ```
4. **Verify:** `df -h` shows headroom again; MySQL writes succeed.
5. **Escalate if:** the growth is in `mysql-data` itself and isn't
   explained by expected data growth — could indicate a bug writing
   unbounded data, worth investigating before just adding disk.

## O. Suspected database corruption

**Symptoms:** MySQL reports table corruption errors, or queries return
obviously wrong results that aren't explained by application logic.

1. **⚠️ Stop here before running anything destructive.** This is the
   one category where "try a few things" is the wrong instinct.
2. **First checks:** the exact MySQL error text (`docker compose logs
   mysql`), `CHECK TABLE <table>;` for the specific table involved.
3. **Do NOT** run `REPAIR TABLE` or any write-touching recovery command
   against a production table without a verified backup already in
   hand — repair operations can themselves be destructive if the
   underlying cause isn't what you think it is.
4. **Recovery path:** take an immediate backup attempt regardless
   (`backup:run` — even a backup of a partially-corrupted database is
   better than nothing for forensics), then restore from the most
   recent known-good verified backup into a scratch database first to
   confirm it's actually intact before considering restoring over
   production (`backup:restore ... --database=<scratch>`, see
   `BACKUPS_DISASTER_RECOVERY.md`).
5. **Escalate immediately** — this category should involve a second
   person before any write-path recovery action, not just a solo
   on-call response.

## P. Suspected security incident

**Symptoms:** unexpected admin account activity, signs of credential
compromise, unexpected data access/exfiltration indicators, or an
unexplained duplicate/integrity-constraint finding (see item J's
escalation note).

1. **First checks:** `admin_audit_logs` for unexpected actor/target
   activity; `payment_webhooks`/`payments` for unexpected gateway
   activity; application logs (`docker compose logs api`) and Sentry
   for unusual error patterns that might indicate probing.
2. **Immediate containment, without destroying evidence:**
   - Deactivate a specific compromised admin account via another
     SuperAdmin (`OPERATIONS_RUNBOOK.md` "Admin operations") — this
     immediately invalidates their sessions (`session_version` bump).
   - **Do not** delete audit log rows or database rows to "clean up" —
     `admin_audit_logs` is append-only by design specifically so it
     can't be tampered with; preserve it for investigation.
3. **Do NOT** attempt to rotate `APP_KEY` as a first response without
   understanding the consequence — it invalidates every existing
   session and any data encrypted with the old key becomes
   unreadable. Appropriate for a confirmed key-compromise scenario,
   not a first guess.
4. **Escalate immediately** — this category is explicitly "stop and get
   help," not "try to resolve it solo from a runbook." Rotate any
   credential you have concrete reason to believe is compromised
   (Razorpay keys, `APP_KEY`, database passwords) through its proper
   channel once you understand the actual scope, not reflexively.

## Q. Total server loss (P12.6)

**Symptoms:** the server itself is unreachable/destroyed — not a
service inside it failing, the whole host is gone (disk failure, the
VM/droplet destroyed, the hosting account compromised).

1. **This is a different category from every item above** — none of
   them help here, because they all assume the server still exists.
   Do not attempt a "restart the container" style fix.
2. **Recovery depends entirely on whether off-server backup
   (`BACKUP_OFFSITE_ENABLED=true`) was actually enabled before the
   server was lost.** If it wasn't, there is no backup to recover from
   — see `BACKUPS_DISASTER_RECOVERY.md` "Local backup vs. off-server
   backup vs. disaster recovery".
3. **If it was enabled:** follow
   `BACKUPS_DISASTER_RECOVERY.md`'s "Disaster recovery procedure" in
   full, start to finish — provision a replacement host, restore
   secrets from the operator's own secret store, retrieve and verify
   the backup, restore, redeploy, verify thoroughly, re-enable
   scheduled jobs, and record the actual recovery time/data-loss
   window.
4. **Escalate immediately** — this is the highest-severity category in
   this document by construction (total data-loss risk, not a
   degraded service), and should involve more than one person from the
   start, same as item P.
