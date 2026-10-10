# RaffleGuru — Backups & Disaster Recovery (P12.2, off-server P12.6)

Status: local backup (P12.2) is implemented and tested. Off-server
backup (P12.6) is implemented, **optional** (disabled by default,
`BACKUP_OFFSITE_ENABLED=false`), and tested against a real S3 API
server (adobe/s3mock) — but **not yet verified against a real cloud
provider or over a real network**, and no real disaster-recovery drill
(restore onto a genuinely different host) has been performed. See
"Off-server backup (P12.6)" and "What was and wasn't verified" below
for exactly what that distinction means before relying on this for a
real production launch.

## What is backed up, and what isn't

- **MySQL database** — the whole `raffleguru` database (schema, data,
  routines, triggers), via a logical `mariadb-dump`/`mysqldump` export.
  This is the one piece of genuinely critical, non-reproducible
  persistent state the application has.
- **Application code** — not backed up here, deliberately: it's in git
  and rebuilt from the repository on every deploy (see
  [`PRODUCTION_ARCHITECTURE.md`](PRODUCTION_ARCHITECTURE.md)). A code
  backup would just be a second, staler copy of git.
- **Redis** — not backed up: cache (`CACHE_STORE=redis`) and queue
  (`QUEUE_CONNECTION=redis`) data only, both intentionally ephemeral and
  safe to lose (cache repopulates; in-flight queued jobs are retried
  work, not a system of record — see the `Payment`/`Order` tables, which
  ARE in MySQL).
- **`storage/app/public` / `storage/app/private`** — as of P12.2, the
  application has **no production-critical uploaded files** in either
  disk (confirmed by inspection: no feature yet writes user/admin
  uploads there — competition/prize images, if ever added, would be the
  first). Nothing to back up today, but the mechanism should extend
  cleanly when that changes: see "Extending to file storage" below.
- **`.env` / secrets** — never backed up (by design: a backup containing
  decryptable production secrets would itself become a thing worth
  attacking). Secrets are operator/deployment-platform state, restored
  by redeploying with the same environment configuration, not by this
  mechanism.

## Architecture

```
scheduler container
  routes/console.php: Schedule::command('backup:run')->dailyAt('02:00')
        │
        ▼
  RunDatabaseBackupCommand
        │
        ├─ DatabaseBackupService::run()
        │     mariadb-dump (--single-transaction, piped straight into
        │     gzip, password via MYSQL_PWD env — never argv) →
        │     {prefix}_{database}_{timestamp}.sql.gz
        │     + a .meta.json sidecar (timestamp, database, environment,
        │       size, sha256, duration)
        │     written to BACKUP_PATH, a Docker named volume
        │     ("backup-data"), NOT the container's own filesystem
        │
        ├─ BackupRetentionService::prune()
        │     deletes dumps (+ sidecars) older than BACKUP_RETENTION_DAYS
        │     — never anything outside BACKUP_PATH, never anything not
        │     matching this app's own filename shape
        │
        └─ (P12.6, only if BACKUP_OFFSITE_ENABLED=true) ──────────────┐
                                                                       ▼
                                                     BackupOffsiteService::upload()
                                                       uploads the SAME dump + .meta.json
                                                       to a dedicated S3-compatible bucket
                                                       (config/filesystems.php's "backups"
                                                       disk — AWS S3, DigitalOcean Spaces,
                                                       Cloudflare R2, ...), then
                                                       downloads it straight back and
                                                       re-hashes it to PROVE the upload is
                                                       intact — never trusts the HTTP 200
                                                       alone. A local-only success is never
                                                       reported as an off-server success —
                                                       see "Off-server backup (P12.6)".
                                                               │
                                                               ▼
                                                 BackupOffsiteRetentionService::prune()
                                                   same idea as local retention, with one
                                                   extra guarantee: NEVER deletes the single
                                                   most recent remote backup, even if it's
                                                   past the retention window.
```

Operator-facing commands (all under `app/Console/Commands/Backup/`,
service logic under `app/Services/Backup/`):

| Command | Purpose |
|---|---|
| `backup:run` | Take a backup, prune locally, then (P12.6, if enabled) upload offsite and prune remotely. What the scheduler calls. |
| `backup:prune` | Local retention cleanup on its own, outside the daily cycle. |
| `backup:verify {file?}` | Prove a backup is restorable (see "Verification"). Defaults to the most recent backup. |
| `backup:restore {file} --database=<target> [--force] [--confirm-production-overwrite]` | Manual, operator-driven restore (see "Restore procedure"). |
| `backup:upload-offsite {file?}` (P12.6) | Upload a specific local backup offsite and verify it, without retaking it. Also what `backup:run` calls internally. |
| `backup:download-offsite {file}` (P12.6) | Retrieve a backup from offsite storage into `BACKUP_PATH`, verified — step 3-4 of disaster recovery (see "Disaster recovery procedure"). `backup:verify`/`backup:restore` then take over unmodified. |
| `backup:prune-offsite` (P12.6) | Offsite retention cleanup on its own. Never deletes the single most recent remote backup. |

## Why mariadb-dump, and the TLS/auth details

The production/Docker image (`infrastructure/docker/php/Dockerfile`)
installs Alpine's `mariadb-client` + `mariadb-connector-c` packages —
`mariadb-dump`/`mariadb` binaries (Alpine's `mysql-client` package is
just a dummy pointing at these; `mysqldump`/`mysql` are deprecated
aliases for them). Two things had to be confirmed by hand before relying
on this, not assumed:

1. **MySQL 8.4's default auth plugin.** `mariadb-client` alone cannot
   load `caching_sha2_password` (the MySQL 8.4 default) — every
   connection fails before authenticating — until
   `mariadb-connector-c` is also installed (it ships the plugin). Both
   packages are required together.
2. **TLS.** `mysql:8.4` generates a fresh self-signed certificate on
   every container (re)creation, which `mariadb-client` refuses to trust
   by default. `config/backup.php`'s `skip_tls` (default `true`) passes
   `tls_disable_flag` (default `--skip-ssl`, mariadb-client's legacy
   boolean) — safe here because MySQL is never reachable outside the
   Docker network (no published ports, see
   [`PRODUCTION_ARCHITECTURE.md`](PRODUCTION_ARCHITECTURE.md)), the same
   trust boundary this project already relies on elsewhere. Revisit if
   MySQL ever moves outside the Docker network (e.g. RDS).

CI's `mysql` job (`.github/workflows/ci.yml`) runs directly on the
GitHub-hosted runner, not inside this project's Docker image, and that
runner has the **real Oracle MySQL 8.0 client** (`mysqldump`/`mysql`),
not `mariadb-client` — confirmed by the job's own pre-existing
`mysqladmin ping` step, which only works if those tools are already
present. The two client flavors do **not** accept the same TLS-disable
flag — confirmed by hand, not assumed, after CI's first run failed with
`mysqldump: [ERROR] unknown option '--skip-ssl'`: Oracle's MySQL 8.0
client removed that legacy option entirely and only accepts
`--ssl-mode=DISABLED`. So `BACKUP_TLS_DISABLE_FLAG` is overridden
per-environment the same way `BACKUP_DUMP_BINARY`/`BACKUP_CLIENT_BINARY`
are — CI sets `--ssl-mode=DISABLED`, Docker keeps the `--skip-ssl`
default — rather than the command-building logic trying to detect which
flavor it's talking to.

## Retention

`BACKUP_RETENTION_DAYS` (default **14**) — this project's own documented
assumption, not an arbitrary enterprise policy: a single-server
deployment with no off-server copy yet (see below) gains little from
keeping local backups much longer than that, and disk usage matters more
on a single server than on a dedicated backup tier. Raise it if disk
allows; revisit once off-server storage exists, since local retention
and off-server retention can reasonably differ once that's true.

Cleanup (`BackupRetentionService::prune()`, called automatically by
`backup:run`, also exposed as standalone `backup:prune`):

- only ever considers files matching this app's own naming shape
  (`{prefix}_{database}_{timestamp}.sql.gz`/`.meta.json`) inside
  `BACKUP_PATH` — never a recursive delete, never a bare wildcard;
- reports exactly what it deleted (filename + size), both to the console
  and via `Log::info('backup.prune.completed', ...)`;
- is pure filesystem logic with no MySQL dependency, so it's covered by
  the fast SQLite test job, not just the MySQL one.

## Verification

A backup that merely exists on disk proves nothing — it could be
truncated, corrupt, or syntactically invalid SQL that would fail
mid-restore during an actual incident. `backup:verify`:

1. confirms the file exists and clears `BACKUP_MINIMUM_SIZE_BYTES`
   (default 512 — comfortably below any real dump of this schema, so
   anything smaller is treated as missing/truncated even if a dump
   process somehow reported success);
2. runs `gzip -t` against it (catches a corrupt/incomplete gzip stream);
3. **actually restores it** into a throwaway database on the same MySQL
   server — named `raffleguru_backup_verify_<random>` — then drops that
   database again in a `finally` block regardless of outcome. **This
   never touches the live application database.**

Step 3 needs a MySQL user with `CREATE`/`DROP DATABASE` privilege, which
the application's normal runtime user intentionally does not have (see
"Local development vs. production" in
[`PRODUCTION_ARCHITECTURE.md`](PRODUCTION_ARCHITECTURE.md) — MySQL is
already set up on least-privilege, database-scoped grants). The one-time
grant needed, scoped by wildcard so it can never reach outside this
app's own verification-database naming pattern:

```sql
GRANT ALL PRIVILEGES ON `raffleguru_backup_verify_%`.* TO '<DB_USERNAME>'@'%';
FLUSH PRIVILEGES;
```

CI runs the equivalent grant automatically (`.github/workflows/ci.yml`,
"Grant backup verification database privilege" step) against its
throwaway `raffleguru_ci` user — that's what makes
`BackupVerificationCommandTest`/`BackupRestoreCommandTest` a real restore
against real MySQL, not a simulation. Production/local operators run the
SQL above once, exactly the same pattern as the existing one-time MySQL
test-database setup documented on `TestCase::MYSQL_TEST_DATABASE`.

**Without this grant**, `backup:verify` fails loudly with a message
naming the missing privilege and pointing back to this section — it does
not fall back to a weaker check or report false success.

## Restore procedure

`backup:restore` is deliberately **manual-only** — nothing in this
codebase ever calls it automatically. It implements steps 1-4 below;
5-9 are intentionally left as documented operator judgment calls, not
automated, because blindly automating them (especially "restart
services" and "validate functionality") during a real incident is itself
a risk:

1. **Select the backup** — `backup:restore <file> ...`; `file` must be
   one of this app's own backup filenames (as listed in `BACKUP_PATH`).
2. **Validate the backup** — run `backup:verify <file>` first. Not
   automatically chained into `backup:restore`, deliberately: verifying
   and then deciding to restore are two separate judgment calls during
   an incident, and chaining them would make a slow/failed verification
   silently block or delay an urgent restore an operator already decided
   on.
3. **Prepare the target database** — `backup:restore` creates `--database`
   if it doesn't already exist (`CREATE DATABASE IF NOT EXISTS`). It
   never drops an existing database first — restoring onto an
   already-populated database relies on the dump's own `DROP TABLE IF
   EXISTS` statements (standard `mariadb-dump`/`mysqldump` output),
   which is a smaller blast radius than this command dropping the whole
   schema itself.
4. **Restore the dump** — streamed in, not buffered in PHP memory first.
5. **Run migrations — only if the backup predates a since-applied
   migration.** Check `php artisan migrate:status` against the restored
   database before deciding; most incident restores (recovering from
   data loss, not from a since-rolled-back migration) need no migration
   at all.
6. **Clear/rebuild caches** — `php artisan optimize:clear` (and
   `php artisan optimize` after, in production) if the restored database
   is about to become the live one.
7. **Restart dependent services** — `docker compose -f
   docker-compose.prod.yml restart api queue scheduler` so no process is
   still holding a connection/prepared statements against pre-restore
   state.
8. **Post-restore health checks** — `GET /api/v1/health/status` (see
   [`PRODUCTION_ARCHITECTURE.md`](PRODUCTION_ARCHITECTURE.md) "Health
   checks").
9. **Validate critical functionality** — at minimum: an admin can log
   in, a competition list loads, and (if this restore is being rehearsed
   rather than used for a real incident) a draw/order flow still
   behaves correctly against the restored data.

### Production safety guards

- `--database` is **mandatory, with no default** — there is no way to
  invoke `backup:restore` that implicitly targets "whatever the app
  happens to be configured to use right now."
- If `--database` names the **live configured database**
  (`config('database.connections.mysql.database')`), the command refuses
  to proceed unless `--confirm-production-overwrite` is also passed —
  two separate, explicitly-named flags, not one.
- Without `--force`, an interactive confirmation describes exactly what
  will happen (target database, dump filename) before anything runs.
- `--database` is validated against `^[a-zA-Z0-9_]+$` before being
  interpolated into `CREATE DATABASE` (MySQL has no bound-parameter form
  of a database identifier in DDL) — not that an operator-supplied value
  is untrusted input in the usual sense, but a last-resort guard against
  a copy-paste mistake producing something unexpected.

## Off-server backup (P12.6)

Disabled by default (`BACKUP_OFFSITE_ENABLED=false`) — local backup
behavior (everything above) is completely unaffected either way, and
`backup:run` behaves exactly as it did in P12.2 until an operator
deliberately opts in here.

### What it does

When enabled, the SAME artifacts `backup:run` already produces locally
(the `.sql.gz` dump + its `.meta.json` sidecar) are also uploaded to a
dedicated object-storage bucket, immediately followed by a **real
download-and-rehash verification** — never trusting the upload HTTP
response alone, the same "prove it, don't assume it" posture
`backup:verify` already takes with a real restore (see "Verification"
above). A local-only success is never reported or logged as an
off-server success: `backup:run`'s output has a distinct
`Offsite upload verified: ...` line, a distinct `backup.offsite_upload.*`
log event, and a failed offsite step makes the whole command exit
non-zero **without undoing the local backup**, which already succeeded
on its own.

### Storage destination

`config/filesystems.php`'s `backups` disk — a **dedicated** disk/
credential pair, deliberately separate from the generic `s3` disk
Laravel ships (reserved for any future user/media-upload feature). A
compromised backup credential and a compromised media credential must
stay two different blast radii, never share one bucket or one key.

Works with any S3-compatible provider via `driver: s3` +
`BACKUP_OFFSITE_ENDPOINT` (+ path-style addressing): AWS S3,
DigitalOcean Spaces, Cloudflare R2, Backblaze B2, Wasabi, or
adobe/s3mock for local/CI testing. No specific provider is assumed or
required.

### Configuration

| Variable | Required | Secret | Default | Purpose |
|---|---|---|---|---|
| `BACKUP_OFFSITE_ENABLED` | no | no | `false` | Master switch. Everything below is irrelevant until this is `true`. |
| `BACKUP_OFFSITE_KEY` | yes (if enabled) | **yes** | — | Access key for the DEDICATED backup bucket — never `AWS_ACCESS_KEY_ID`. |
| `BACKUP_OFFSITE_SECRET` | yes (if enabled) | **yes** | — | Secret key, same dedicated credential. |
| `BACKUP_OFFSITE_BUCKET` | yes (if enabled) | no | — | The dedicated backup bucket name. |
| `BACKUP_OFFSITE_REGION` | no | no | `auto` | A real AWS region if using AWS S3 directly; `auto` is correct for Cloudflare R2. |
| `BACKUP_OFFSITE_ENDPOINT` | yes for non-AWS providers | no | — | e.g. `https://<account>.r2.cloudflarestorage.com`, or `http://127.0.0.1:9090` for the local/CI substitute. Leave unset for real AWS S3. |
| `BACKUP_OFFSITE_USE_PATH_STYLE_ENDPOINT` | no | no | `true` | Most S3-compatible providers need path-style; real AWS S3 generally doesn't. |
| `BACKUP_OFFSITE_SSE_ALGORITHM` | no | no | `AES256` | Server-side encryption — see "Encryption" below. Set to an empty string only for a provider that rejects SSE without its own KMS configured (e.g. a bare MinIO instance — not a concern for this project's own adobe/s3mock test substitute, which accepts it). |
| `BACKUP_OFFSITE_RETENTION_DAYS` | no | no | `30` | Deliberately longer than the local 14-day default — see "Retention" below. |
| `BACKUP_OFFSITE_UPLOAD_ATTEMPTS` | no | no | `3` | How many times the whole upload-and-verify sequence retries before giving up — see "Reliability" below. |
| `BACKUP_OFFSITE_SDK_RETRIES` / `BACKUP_OFFSITE_CONNECT_TIMEOUT` / `BACKUP_OFFSITE_TIMEOUT` | no | no | `3` / `10s` / `120s` | Lower-level AWS SDK retry/timeout behavior for a single HTTP request, underneath the application-level retry above. |

### Least-privilege bucket policy

Operator-provisioned, not automated by this repo (same posture as every
other piece of cloud infrastructure this project documents but doesn't
provision — see "AWS direction" in
[`PRODUCTION_ARCHITECTURE.md`](PRODUCTION_ARCHITECTURE.md)). The
credential this app uses needs, at minimum: `s3:PutObject`,
`s3:GetObject`, `s3:ListBucket`, and `s3:DeleteObject`, scoped to the
one dedicated bucket only — never account-wide S3 access, never a
credential shared with anything else. The bucket itself should have
**all public access blocked** (this app never sets public ACLs or
bucket policies, and never should) and, if the provider supports it, a
lifecycle rule to **abort incomplete multipart uploads** after a few
days — a crashed upload can otherwise leave unreferenced storage behind
that this app's own retention logic (which only ever sees completed
objects) can't clean up.

### Reliability

- **Retries**: the whole upload-and-verify sequence (not just a single
  HTTP request — the AWS SDK itself already retries a request via
  `BACKUP_OFFSITE_SDK_RETRIES`) retries up to `BACKUP_OFFSITE_UPLOAD_ATTEMPTS`
  times, with a delay between attempts, before `BackupOffsiteException`
  propagates as a visible failure. Every attempt re-checks reality from
  scratch (existence, size) rather than trusting a previous attempt's
  partial result.
- **Idempotency**: re-running `backup:upload-offsite` for a backup
  that's already present and the correct size skips re-uploading the
  bytes, but still re-downloads and re-verifies the hash every time —
  "already there" is never treated as "therefore still intact" without
  re-checking. If the remote object is corrupted (same size, wrong
  bytes), the next attempt detects the mismatch, deletes it, and
  re-uploads the correct local bytes — a form of self-healing that
  falls out of the same retry loop, not a separate "repair" code path.
- **Atomicity**: a single `PutObject` either completes or the object
  was never created — S3-compatible APIs don't expose a partially-
  written object to a concurrent `GetObject`/`HeadObject`. The only
  storage-cost risk from an interrupted upload is an abandoned
  multipart upload, addressed by the lifecycle rule above, not by
  application code.
- **Download/restore**: `backup:download-offsite` writes to a
  `.partial` file first, verifies its SHA-256 against the remote
  `.meta.json`'s recorded hash, and only then atomically renames it
  into `BACKUP_PATH` under its real name — the same
  temp-then-atomic-rename pattern `DatabaseBackupService` already uses
  for a freshly-taken local backup. A failed/mismatched download is
  deleted, never left behind under a trusted-looking filename.

### Retention

`BACKUP_OFFSITE_RETENTION_DAYS` (default **30**, longer than local's 14
— off-server storage is the copy that survives losing the server, so
keeping more history costs object-storage pennies, not scarce local
disk). `BackupOffsiteRetentionService::prune()`:

- only ever considers objects matching this app's own naming shape
  (reusing `BackupPaths::isOwnedDumpFilename()` — the identical guard
  local retention uses) — never touches an unrelated object sharing the
  bucket;
- ages a backup by the **timestamp embedded in its own filename**, not
  by when the provider recorded the object as uploaded — a retried or
  late upload of an old backup must not look "fresh" to retention just
  because it landed in the bucket recently;
- **never deletes the single most recent remote backup, even if it's
  already past the retention window.** This is the one rule local
  retention doesn't need: a local backup is always about to be
  refreshed again tomorrow on the same server, but if offsite uploads
  have been silently failing for longer than the retention window, the
  one remaining remote copy is the ONLY thing standing between "lost
  data" and "a slow recovery" — pruning it away because it happens to
  be old would make the off-server copy actively less safe than having
  no retention policy at all. `backup:prune-offsite` surfaces this as an
  explicit warning (`newest_past_retention`) rather than hiding it
  inside a quiet "nothing to prune" message — treat that warning as an
  operational incident, not routine output.
- runs automatically after every successful `backup:upload-offsite` /
  offsite-enabled `backup:run` — **only after** the new upload has been
  verified, never before, so a newer backup is always confirmed good
  before anything older is removed.

### Encryption

**Provider-side (server-side encryption, SSE) is applied by default**
— `BACKUP_OFFSITE_SSE_ALGORITHM=AES256` is passed on every write (see
`config/filesystems.php`'s `backups` disk `options`). What this
actually protects against: a stolen/decommissioned physical disk at the
storage provider's data center. What it does **not** protect against:
anyone who already has valid API credentials for the bucket — SSE is
transparent to any authenticated read, so a leaked `BACKUP_OFFSITE_KEY`/
`BACKUP_OFFSITE_SECRET` still exposes plaintext data through the normal
API. The real protection against *that* is the least-privilege,
dedicated-credential bucket policy above, not encryption.

**Application-side (client-side) encryption was deliberately NOT
implemented.** Encrypting the dump before upload would mean this
project becomes responsible for a NEW secret — the encryption key
itself — with its own disaster-recovery problem: lose that key and
every backup encrypted with it is unrecoverable regardless of how good
the backup itself is, which is a strictly worse failure mode than "no
encryption" for a project with no key-management infrastructure (a
KMS-backed key, with its own access policy and rotation story) today.
If this is ever added, design the key's own storage and recovery FIRST
— and never store it in the same bucket, the same repository, or
alongside the backups it encrypts — rather than retrofitting that after
the fact. Not implemented here is a deliberate scope decision, not an
oversight, matching this phase's own task boundaries.

### Running the offsite tests locally

The `#[Group('mysql')]` tests under `tests/Feature/Backup/Offsite/`
need a real S3 API server — never a PHP-level mock of this project's
own code — the same "prove it against the real thing" posture as the
existing MySQL-concurrency tests needing a real MySQL server.

**Why adobe/s3mock, not MinIO**: MinIO was this phase's original
choice, and still works as a drop-in replacement if that ever changes
back — this project's code only talks to Laravel's generic Filesystem
contract, never anything MinIO-specific. It was swapped out because
MinIO's own Docker images were removed from both Docker Hub and
quay.io in September 2026 and the upstream project itself was
archived, confirmed by hand while building this phase (this repo's own
CI failed with "pull access denied for minio/minio" before the switch)
— not a preference, a necessity. adobe/s3mock is a real HTTP server
implementing the real S3 REST API (every test makes a genuine network
call through the real AWS SDK), with one honest limitation: **it never
validates credentials at all**, by its own documented design — see
`BackupOffsiteUploadTest`'s comment on the one test scenario this
means can't be exercised against it.

One-time local setup (mirrors the existing one-time MySQL
test-database setup documented on `TestCase::MYSQL_TEST_DATABASE`):

```sh
docker run -d --name raffleguru-s3mock-dev \
  -p 9090:9090 \
  adobe/s3mock:5.2.3

curl -X PUT http://127.0.0.1:9090/raffleguru-backups-test
```

`phpunit.xml`'s `BACKUP_OFFSITE_TEST_*` defaults already point at
exactly this (port 9090, this same bucket name — s3mock ignores
whatever access key/secret you send it, so the credential values don't
matter) — no further configuration needed. CI
(`.github/workflows/ci.yml`'s `mysql` job) starts the identical
container the same way, as a plain step rather than a `services:`
entry, so the bucket-creation step can run only after confirming the
server actually answers.

## Local backup vs. off-server backup vs. disaster recovery

These are three different guarantees:

1. **Local backup** (P12.2): protects against *data* mistakes — a bad
   migration, an accidental delete, application-level corruption — on a
   server that is otherwise fine. The backup lives on `backup-data`, a
   Docker volume on the **same host** as `mysql-data`.
2. **Off-server backup** (P12.6, optional — see above): protects
   against *this specific server* being lost — disk failure, the
   VM/droplet being destroyed, the hosting account being compromised.
   **Local backup alone does not provide this.** Implemented and tested
   against a real S3 API server (adobe/s3mock); **not yet verified
   against a real cloud provider (AWS S3/R2/Spaces/etc.) over a real
   network**, and not yet enabled in any real deployment (none exists
   yet — see `PRODUCTION_ARCHITECTURE.md`).
3. **Disaster recovery**: the documented procedure (see "Disaster
   recovery procedure" below) to bring the application back up on a
   *different* server using an off-server backup. Documented and its
   individual steps tested (download → verify → restore, end-to-end,
   against the real S3 mock substitute and a real MySQL restore); **the
   full procedure has never been rehearsed against an actual newly
   provisioned host** — see "Recovery objectives" for what that means
   for RTO specifically.

## Disaster recovery procedure

For when the application server itself (not just its data) is lost.
Assumes off-server backup (above) was enabled and at least one upload
succeeded before the server was lost — if it wasn't, there is nothing
for this procedure to recover from; see "Dependencies that would
prevent recovery" below.

1. **Provision a replacement host.** Any server meeting
   [`PRODUCTION_DEPLOYMENT.md`](PRODUCTION_DEPLOYMENT.md) §1's Docker
   Engine + Compose v2 requirement. Not automated by this repo — same
   "operator-provisioned infrastructure" posture as the original host.
2. **Restore configuration and secrets.** `apps/api/.env`, the root
   `.env`, `APP_KEY`, `RAZORPAY_*`, `BACKUP_OFFSITE_*`, TLS certificates
   if using Option C (`docs/PRODUCTION_DEPLOYMENT.md` §2) — from
   whatever secure secret store the operator keeps these in **outside**
   this repository (this repo never stores or backs up secrets itself —
   see "What is backed up, and what isn't" above). If that secret store
   was ALSO only reachable through the lost server, recovery stops here
   — see "Dependencies" below.
3. **Retrieve the backup**: `docker compose -f docker-compose.prod.yml
   exec api php artisan backup:download-offsite <filename>` (requires
   `BACKUP_OFFSITE_*` already configured from step 2). Lists available
   backups first if the exact filename isn't known — see
   `backup:prune-offsite`'s output or the provider's own bucket listing.
4. **Validate checksum and compression integrity**: `download-offsite`
   already verified the SHA-256 against the remote `.meta.json` as part
   of step 3 — run `backup:verify <filename>` next anyway (gzip
   integrity + a real restore into a throwaway database, see
   "Verification" above) before trusting it for the real restore in the
   next step. Two independent checks, not one relied on twice.
5. **Restore the database** using the existing guarded workflow —
   `backup:restore <filename> --database=<target>` — see "Restore
   procedure" above. Nothing about this step changes because the backup
   arrived via offsite download rather than a local `backup:run`.
6. **Deploy the application.** `git checkout` the commit/tag that was
   actually running when the server was lost (not necessarily `main`'s
   latest), build and start the stack per
   [`PRODUCTION_DEPLOYMENT.md`](PRODUCTION_DEPLOYMENT.md) §7/§10. Run
   `php artisan migrate --force` **only if** `migrate:status` against
   the just-restored database shows pending migrations — most disaster
   recoveries restore a backup that's already fully migrated.
7. **Verify thoroughly**, not just the health endpoint: `GET /up` and
   `GET /api/v1/health/status` first, then exercise the actual
   application — an admin can log in, the competition list loads, a
   test registration/login works, and (without creating real financial
   side effects) that order/payment/ticket-allocation/draw/winner/claim
   data is visibly present and consistent with what the restored backup
   should contain. See
   [`PRODUCTION_SMOKE_TEST.md`](PRODUCTION_SMOKE_TEST.md) for the full
   checklist — run it in full here, not just the "after a routine
   deploy" subset.
8. **Re-enable scheduled jobs and queue workers.** `docker compose
   -f docker-compose.prod.yml up -d` already starts `queue`/`scheduler`
   as long-running containers (see
   [`PRODUCTION_DEPLOYMENT.md`](PRODUCTION_DEPLOYMENT.md) §10) — confirm
   both are actually running and not restart-looping before considering
   the recovery complete, since a restored-but-not-processing
   application will silently fall behind on ticket allocation/draws/
   winners.
9. **Record recovery time and data-loss window.** Wall-clock time from
   "server lost" (or "recovery begun," if that's clearer) to step 7
   passing, and the real gap between the restored backup's timestamp
   and when the server was lost — this is the actual RPO/RTO for THIS
   incident, not the aspirational numbers below. Feed it back into
   "Recovery objectives" the next time this document is revisited.

**This procedure has not been rehearsed end-to-end against a genuinely
new host** as part of this phase — see "What was and wasn't verified"
below for exactly which pieces of it (steps 3-5 specifically) were
exercised for real.

## Recovery objectives

- **RPO (Recovery Point Objective): ~24 hours**, unchanged from P12.2
  and **not improved by P12.6** — off-server backup doesn't take
  backups more often, it protects the SAME daily backup from being lost
  along with the server. Losing data since the last 2am backup is
  possible by design; binlog-based point-in-time recovery is a real
  option if this ever needs to tighten, not implemented here.
- **RTO (Recovery Time Objective): unverified.** Restoring onto the
  *same* server is fast and routinely exercised (a `backup:restore` of
  this schema's current size). Recovering onto a *different*, freshly
  provisioned server has never been timed end-to-end — do not quote a
  specific RTO number to anyone until "Disaster recovery procedure"
  above has actually been run against a real replacement host and
  timed, per step 9.

### Dependencies that would prevent recovery

None of these are backed up or protected by anything in this document
— losing any one of them turns "follow the procedure above" into a much
harder, possibly unrecoverable, problem:

- **The operator's secret store itself** (wherever `APP_KEY`,
  `BACKUP_OFFSITE_SECRET`, `RAZORPAY_KEY_SECRET`, etc. actually live) —
  if that was only reachable through the lost server, or was never
  actually independent of it, there is no recovery path this document
  can describe.
- **Off-server storage access** — a revoked/expired/lost
  `BACKUP_OFFSITE_KEY`/`BACKUP_OFFSITE_SECRET`, or the bucket itself
  being deleted (e.g. the same compromised cloud account that took the
  server down also controlled the bucket — see "Off-server backup"
  above for why a DEDICATED credential, not a shared one, at least
  narrows this).
- **Encryption keys** — not applicable today (application-side
  encryption is not implemented, see "Encryption" above); if it ever
  is, a lost key would belong on this list too.
- **Payment-provider credentials** (`RAZORPAY_KEY_SECRET`/
  `RAZORPAY_WEBHOOK_SECRET`) — without these, the restored application
  boots (see the production guard in
  [`PRODUCTION_DEPLOYMENT.md`](PRODUCTION_DEPLOYMENT.md) §6, which
  refuses `PAYMENT_GATEWAY=fake` but has nothing to say about a simply
  *missing* real secret) but cannot process new payments or verify new
  webhooks until Razorpay's dashboard is used to re-establish them.

### Operator checklist for periodic restore drills

Not automated, not yet performed even once as of P12.6 — a checklist
for the FIRST time this should actually happen, not a report that it
already has:

- [ ] Pick a non-production MySQL target (never the real `raffleguru`
      database) and run `backup:download-offsite` + `backup:verify` +
      `backup:restore --database=<throwaway>` against the most recent
      real offsite backup.
- [ ] Time every step from "decided to drill" to "restored database
      verified functional" — this is the number that actually populates
      "RTO" above, not a guess.
- [ ] Confirm the restored data's newest row timestamps match the
      expected backup age (proves the RIGHT backup was restored, not an
      old one that happened to still exist).
- [ ] If this drill reveals a step in "Disaster recovery procedure"
      above that doesn't actually work as written, fix the document
      (or the code) before the next drill, not after a real incident
      finds the same gap.
- [ ] Repeat on a schedule (quarterly is a reasonable starting cadence
      for a project this size — not an enforced/automated reminder,
      an operator calendar entry).

## Extending to file storage

If/when the application starts writing production-critical files to
`storage/app/public` or `storage/app/private` (e.g. competition/prize
images), extend `DatabaseBackupService`'s sibling — don't bolt file
backup onto the database backup command. The natural shape: a second
command (`backup:run-storage` or similar) using the same
`BackupPaths`/`BackupRetentionService` machinery, archiving only the
specific disk(s) that then hold real user/business data — explicitly
never `storage/framework/*` (cache/views/sessions — reproducible) or
`storage/logs/*` (see
[`PRODUCTION_ARCHITECTURE.md`](PRODUCTION_ARCHITECTURE.md)
"Observability" for where logs actually go in production).

## Security

- Backups can contain customer/order/payment-adjacent/admin data — same
  sensitivity as the live database, not a lesser copy of it.
- The database password reaches `mariadb-dump`/`mariadb` via the
  `MYSQL_PWD` environment variable, **never** a CLI argument — the
  process table (`ps`) on a shared host can read another process's
  argv, not its private environment block.
- Backup files are written `0640`, the backup directory `0750`
  (`DatabaseBackupService`/`BackupPaths`).
- `backup:verify`/`backup:restore` never write decompressed SQL to disk
  at all — the gzip stream is decompressed in-memory, chunk by chunk,
  straight into the restoring client's stdin
  (`BackupVerificationService`/`BackupRestoreService`'s
  `decompressedChunks()`). A plaintext copy of a full database dump
  sitting in shared temp space, even briefly and even with tight
  permissions, is a risk this avoids by construction rather than
  mitigating.
- `backup-data` is mounted only on the `api` and `scheduler` services in
  `docker-compose.prod.yml` — never on `nginx`, so a backup file can
  never be served as a public web asset by construction, not by relying
  on an Nginx rule to exclude it.
- Nothing here logs the database password or prints it to the console —
  `DatabaseBackupService`'s stderr handling is a defensive backstop
  against a future flag change ever putting a secret on stderr, given
  the password is never on the command line to begin with.
- Local encryption at rest is **not implemented** — the backup
  directory's filesystem permissions are the only local protection.
  (P12.6's offsite copy DOES get provider-side SSE by default — see
  "Encryption" under "Off-server backup (P12.6)" above — but that's a
  property of the remote object, not the local file.)
- **P12.6**: `BACKUP_OFFSITE_KEY`/`BACKUP_OFFSITE_SECRET` are a
  DEDICATED credential, deliberately never `AWS_ACCESS_KEY_ID`/
  `AWS_SECRET_ACCESS_KEY` (reserved for any future user/media-upload
  feature) — a leaked backup credential and a leaked media credential
  must stay two separate blast radii. Never logged: every exception
  path in `BackupOffsiteService` sanitizes its message against the
  configured secret before it reaches a log line or a console error
  (see `sanitizedMessage()`) — defensive, the same posture as the local
  `MYSQL_PWD` handling above, given AWS SigV4 itself never puts the raw
  secret in a request in the first place.
- **P12.6**: the offsite upload is verified by downloading the object
  straight back and hashing it in a streaming loop — same "never
  buffer a full plaintext dump in memory/temp space" posture as local
  verify/restore above, just applied to the network round-trip instead
  of the local gzip stream.

## What was and wasn't verified (P12.6)

Stated explicitly so nothing here is mistaken for more than it is:

**Verified, against adobe/s3mock — a real HTTP server implementing the
real S3 REST API, exercising the real AWS SDK and this project's real
`league/flysystem-aws-s3-v3` adapter code (never a PHP-level stub of
this project's own `BackupOffsiteService`) — by this phase's automated
test suite (`tests/Feature/Backup/Offsite/`) and run again as part of
this report:**

- Upload + real download-and-rehash verification succeeding
- Upload idempotency (re-running for an already-uploaded file)
- A remote object corrupted after upload being detected via checksum
  mismatch — and, given more than one attempt, self-healing via retry
- Retry-then-give-up behavior on a persistently unreachable endpoint,
  and that the configured secret never appears in a resulting error
  message
- `backup:download-offsite` retrieving a backup, verifying it against
  the remote `.meta.json`, and the **existing, completely unmodified**
  `backup:verify` command successfully restoring that downloaded file
  into a throwaway database (a real MySQL restore)
- Retention: deleting only objects past the window, by filename
  timestamp rather than upload time, never touching an unrelated
  object in the same bucket, and never deleting the single most recent
  object even when it's past the window
- `backup:run` chaining the offsite upload when enabled, and the local
  backup remaining fully intact when the offsite half is made to fail
- The full pre-existing local backup/verify/restore/retention test
  suite (P12.2) still passing unmodified, alongside everything above,
  with zero new failures anywhere else in the application's test suite

**NOT verified — do not claim otherwise:**

- Any upload/download against a real cloud provider (AWS S3,
  Cloudflare R2, DigitalOcean Spaces, etc.) — adobe/s3mock exercises
  this code's actual network/SDK/adapter logic faithfully, but it is
  not evidence about a specific cloud provider's actual network
  latency, IAM behavior, or edge cases.
- **Credential rejection specifically** — adobe/s3mock never validates
  access keys/secrets at all, by its own documented design (it tells
  callers to "supply any dummy credentials"). This project's code path
  for a storage failure (clean error, no secret in the message) is
  verified against an unreachable endpoint instead, which goes through
  the identical exception-handling code — but whether a REAL
  provider's `AccessDenied`/`InvalidAccessKeyId` response surfaces the
  same way has not been exercised.
- A real certificate/credential rotation, a real bucket lifecycle
  policy, or a real multipart-upload-abort scenario
- A real 30-day (or longer) retention cycle elapsing naturally
- A real disaster-recovery drill against a genuinely new, separately
  provisioned host — see "Disaster recovery procedure" and its
  operator checklist above; this has never been performed, not even
  once, as of this phase
- Any real RTO number — see "Recovery objectives" above
- Off-server backup enabled in any real deployment — none exists yet
  (see [`PRODUCTION_ARCHITECTURE.md`](PRODUCTION_ARCHITECTURE.md))
