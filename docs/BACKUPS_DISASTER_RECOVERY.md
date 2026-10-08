# RaffleGuru — Backups & Disaster Recovery (P12.2)

Status: local backup foundation, implemented and tested. Off-server
storage is **not yet configured** — see "Local backup vs. off-server
backup vs. disaster recovery" below for exactly what that means and what
closing the gap requires.

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
        └─ BackupRetentionService::prune()
              deletes dumps (+ sidecars) older than BACKUP_RETENTION_DAYS
              — never anything outside BACKUP_PATH, never anything not
              matching this app's own filename shape
```

Operator-facing commands (all under `app/Console/Commands/Backup/`,
service logic under `app/Services/Backup/`):

| Command | Purpose |
|---|---|
| `backup:run` | Take a backup, then prune. What the scheduler calls. |
| `backup:prune` | Retention cleanup on its own, outside the daily cycle. |
| `backup:verify {file?}` | Prove a backup is restorable (see "Verification"). Defaults to the most recent backup. |
| `backup:restore {file} --database=<target> [--force] [--confirm-production-overwrite]` | Manual, operator-driven restore (see "Restore procedure"). |

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

## Local backup vs. off-server backup vs. disaster recovery

These are three different guarantees, and this phase only delivers the
first:

1. **Local backup** (what P12.2 builds): protects against *data*
   mistakes — a bad migration, an accidental delete, application-level
   corruption — on a server that is otherwise fine. The backup lives on
   `backup-data`, a Docker volume on the **same host** as
   `mysql-data`.
2. **Off-server backup** (not yet configured): protects against *this
   specific server* being lost — disk failure, the VM/droplet being
   destroyed, the hosting account being compromised. **Local backup
   alone does not provide this** — if the server is gone, `backup-data`
   is gone with it, no matter how good the retention policy is.
3. **Disaster recovery**: the documented, rehearsed procedure to bring
   the application back up on a *different* server using an off-server
   backup. This requires (2) to exist first; it does not exist yet
   either.

**This phase deliberately does not add AWS S3/Cloudflare R2/etc.** — the
architecture rule governing this phase was to build the provider-neutral
local foundation first and document the real requirement, rather than
wire up a specific cloud provider with no evidence it's the one this
project will actually use (see the "AWS direction" section of
[`PRODUCTION_ARCHITECTURE.md`](PRODUCTION_ARCHITECTURE.md) — still
documentation-only, not provisioned).

**What closing this gap requires, when it's time:** a scheduled copy of
`BACKUP_PATH`'s contents to object storage (S3 or equivalent) after each
successful `backup:run` — the filename/metadata shape already in place
(`{prefix}_{database}_{timestamp}.sql.gz` + `.meta.json`) is exactly what
that sync step would need, so no re-architecture should be required, just
an additional step appended to the existing pipeline.

**RPO/RTO, honestly stated given the above:**

- **RPO (Recovery Point Objective): ~24 hours** — the interval between
  daily backups. No point-in-time recovery; losing data since the last
  2am backup is possible by design (binlog-based PITR is a real option
  if this ever needs to tighten, not implemented here).
- **RTO (Recovery Time Objective): not yet meaningfully defined** —
  restoring onto the *same* server is fast (a `backup:restore` of this
  schema's current size), but recovering from *losing the server* is
  bounded by (2)/(3) above not existing yet, which this document is not
  going to pretend otherwise about.

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
- Encryption at rest is **not implemented** in this phase — the backup
  directory's filesystem permissions are the only protection. If this is
  ever added, it must use real key management (e.g. a KMS-backed key,
  not a key committed to the repo or baked into the image) — explicitly
  out of scope here rather than faked.
