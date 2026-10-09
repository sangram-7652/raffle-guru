# CI / test infrastructure (P11.13)

Why the backend test suite runs on two different databases, and the rules
that keep both safe.

## Two CI jobs, one suite

`.github/workflows/ci.yml` runs the Laravel backend suite twice:

- **`backend`** — SQLite `:memory:`, the fast path. Runs everything
  except the tests tagged `#[Group('mysql')]`
  (`vendor/bin/phpunit --exclude-group mysql`).
- **`mysql`** — a real `mysql:8.4` service container, scoped to *only*
  the `#[Group('mysql')]` tests (`vendor/bin/phpunit --group mysql`).

This is not "run the suite twice for safety" — the two jobs cover
disjoint test sets, kept apart because they test different things and
need different guarantees from the database underneath them:

- Most of the suite exercises application logic against one connection.
  SQLite `:memory:` is enough for that, and it's fast — no container to
  boot, no network round-trip per query.
- A handful of tests exist specifically to prove row-locking under real
  concurrent transactions, or need a real `mariadb-dump`/`mariadb`
  binary that SQLite has no equivalent of
  (`AdminBootstrapConcurrencyTest`, `AdminManagementConcurrencyTest`,
  `LifecycleConcurrencyTest`, `DrawConcurrencyTest`,
  `Ticket\ConcurrencyTest`, `WinnerConcurrencyTest`,
  `Payment\PaymentConcurrencyTest` (P12.3), plus three backup command
  tests that exercise a real dump/restore round-trip
  (`Backup\DatabaseBackupCommandTest`, `Backup\BackupVerificationCommandTest`,
  `Backup\BackupRestoreCommandTest`, P12.2) and
  `Admin\AdminBootstrapCommandTest`). Each concurrency test forks real
  child processes (`pcntl_fork()`), each with its own database
  connection, racing against each other on purpose. SQLite's
  `:memory:` database is private to the connection that created it — a
  forked child never sees the parent's rows, so there is no real lock
  to contend over and the "race" proves nothing. These tests need
  MySQL's actual InnoDB row-lock behavior (or a real MySQL client
  binary), or they aren't testing the thing they claim to test.

As of P12.3: **875 tests** in the SQLite (`backend`) job, **32 tests**
in the `mysql` job (11 test classes carrying `#[Group('mysql')]`).
Running the whole SQLite suite against MySQL too would roughly double
CI time and runner cost for no additional coverage — the other 875
tests pass identically on both engines. Hence the split, and hence
scoping the `mysql` job to the tagged group instead of the full suite.
(These exact counts will drift as the suite grows — treat them as "as
of P12.4," not a promise; `vendor/bin/phpunit --exclude-group mysql`
and `--group mysql` are the source of truth, not this paragraph.)

**P12.2 addition:** the `mysql` job also runs a one-time "Grant backup
verification database privilege" step (root-authenticated, scoped by
wildcard to `raffleguru_backup_verify_%`) so
`backup:verify`/`backup:restore`'s real-restore tests can create/drop
their own throwaway databases — see
[`BACKUPS_DISASTER_RECOVERY.md`](BACKUPS_DISASTER_RECOVERY.md)
"Verification" for the equivalent one-time production/local grant.

## Adding a new MySQL-only test

Give the test class `#[Group('mysql')]` (see
`PHPUnit\Framework\Attributes\Group`) and call
`TestCase::useIsolatedMysqlDatabase()` in `setUp()` — follow any existing
file in the group above. It will then automatically run in the `mysql`
CI job and be excluded from the `backend` (SQLite) job. Don't add a new
MySQL-only test without the attribute — it will fail in the `backend`
job for the same structural reason the existing ones did before this
tag existed.

## `TESTING_DB=mysql`

`tests/bootstrap.php` only switches the *default* Laravel connection to
MySQL when `TESTING_DB=mysql` is set in the environment before the app
boots (phpunit.xml's `force="true"` env values only affect `$_ENV`, not
`$_SERVER`, which is what Laravel's env repository actually reads first —
see the comment in `tests/bootstrap.php`). Locally:

```
docker compose exec -e TESTING_DB=mysql api php vendor/bin/phpunit --group mysql
```

Individual concurrency tests additionally call
`TestCase::useIsolatedMysqlDatabase()` themselves, which is what actually
switches `database.default` to `mysql` for their own duration (plain
`TESTING_DB=mysql` alone would still run everything else on the
now-selected MySQL connection too, which isn't what any non-concurrency
test wants or needs).

## Why `raffleguru_testing`, never `raffleguru`

`Tests\TestCase::createApplication()` refuses to boot the test
application at all unless the active connection is either SQLite
`:memory:` or a MySQL database whose name ends in `_testing` — checked
*before* any test trait (including `RefreshDatabase`) runs, so a
misconfigured environment aborts instead of silently running
`migrate:fresh` against whatever database it happens to be pointed at.
`raffleguru_testing` is the one MySQL database that guard allows. This
is the same safeguard whether the suite runs locally, in the `backend`
SQLite job, or in the `mysql` job — nothing in this phase changed or
weakened it.

In CI, `raffleguru_testing` is created and destroyed as a GitHub Actions
service container scoped to the `mysql` job's own lifetime (see
`services:` in `ci.yml`) — it is never the development or production
`raffleguru` database, there is no way for CI to reach that database at
all, and the container's throwaway credentials (`raffleguru_ci` /
`ci_test_password`) are not real secrets and grant access to nothing
outside that ephemeral container.

## What P11.13 fixed, not just worked around

Two tests failed specifically on SQLite for reasons that had nothing to
do with the application behavior they were asserting:

- `WebhookTest::test_duplicate_webhook_delivery_is_processed_only_once` —
  `PaymentWebhookController` detected an already-processed webhook by
  matching MySQL's error-message text, which names the violated index;
  SQLite's message format doesn't. Fixed by checking the PDO SQLSTATE
  class (`'23000'`, the ANSI-standard "integrity constraint violation"
  code every PDO driver reports identically) instead of driver-specific
  message text.
- `EntryApiTest::test_entry_resource_never_exposes_another_users_data_or_internal_ticket_ids` —
  asserted that a ticket's internal database id never equals its public
  ticket number, but a fresh SQLite `:memory:` database's autoincrement
  made the test's own first ticket's internal id and public number both
  legitimately equal `1` — a coincidence, not a leak. Fixed with a decoy
  fixture that deterministically pushes the real ticket's internal id
  outside the {1, 2, 3} range its ticket numbers always occupy.

A third, more serious issue was found while verifying the `mysql` group
actually passes against real MySQL (it had never reliably done so):
`DrawConcurrencyTest::tearDown()` deleted `draw_results` without first
deleting the `winners` row that `CreateWinnerJob` creates synchronously
under the test env's `QUEUE_CONNECTION=sync` — a real foreign-key
violation on real MySQL, unrelated to SQLite at all. Fixed by extending
the manual cleanup chain to cover `winners` and its own dependents
(`winner_audit_logs`, `winner_claims`, `prize_deliveries`) before
`draw_results`.

None of the three fixes touch the invariant each test exists to verify —
duplicate webhooks still can't double-process, internal ids still can't
leak as ticket numbers, and the draw-concurrency assertions are
unchanged. See `CHANGELOG`-equivalent commit messages for the exact
diffs.
