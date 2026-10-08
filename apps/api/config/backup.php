<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Backup storage path
    |--------------------------------------------------------------------------
    |
    | MUST be backed by a persistent Docker volume in production, not the
    | default ephemeral container filesystem — see docker-compose.prod.yml
    | ("backup-data" volume, mounted on the api and scheduler services) and
    | docs/BACKUPS_DISASTER_RECOVERY.md. The local/dev default lives under
    | storage/app/ so the commands work out of the box without any .env
    | changes.
    |
    */
    'path' => env('BACKUP_PATH', storage_path('app/backups')),

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Backup artifacts (and their .meta.json sidecars) older than this many
    | days are deleted by `backup:prune`, which `backup:run` calls
    | automatically after every successful backup. 14 days is this
    | project's documented assumption for a single-server deployment with
    | no off-server copy configured yet — see
    | docs/BACKUPS_DISASTER_RECOVERY.md "Retention" for the reasoning, and
    | "Local backup vs. off-server backup vs. disaster recovery" for why a
    | local-only backup is not by itself a real DR story regardless of how
    | long it's retained.
    |
    */
    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 14),

    /*
    |--------------------------------------------------------------------------
    | Filename prefix / suffixes
    |--------------------------------------------------------------------------
    |
    | backup:prune only ever matches files of the form
    | "{prefix}_{database}_{timestamp}{dump_extension|meta_extension}"
    | inside the configured path — never a bare wildcard — so a typo'd
    | BACKUP_PATH pointed at some other directory can't cause it to delete
    | files it didn't create.
    |
    */
    'prefix' => env('BACKUP_FILENAME_PREFIX', 'raffleguru'),
    'dump_extension' => '.sql.gz',
    'meta_extension' => '.meta.json',

    /*
    |--------------------------------------------------------------------------
    | mariadb-dump / mariadb client binaries
    |--------------------------------------------------------------------------
    |
    | Alpine's `mysql-client` package is a transitional dummy pointing at
    | `mariadb-client` (see infrastructure/docker/php/Dockerfile) — its
    | `mysqldump`/`mysql` binaries are deprecated aliases for
    | `mariadb-dump`/`mariadb`. Using the non-deprecated names directly
    | avoids a "deprecated program name" warning on stderr every run.
    | Compatibility confirmed by hand before writing this code: a real
    | mariadb-client 11.8.8 dump + restore against a real mysql:8.4.11
    | server (the same image docker-compose*.yml uses), not assumed.
    |
    */
    'dump_binary' => env('BACKUP_DUMP_BINARY', 'mariadb-dump'),
    'client_binary' => env('BACKUP_CLIENT_BINARY', 'mariadb'),

    /*
    |--------------------------------------------------------------------------
    | TLS
    |--------------------------------------------------------------------------
    |
    | mysql:8.4 generates a fresh self-signed certificate on every container
    | (re)creation, which mariadb-client refuses by default ("self-signed
    | certificate in certificate chain"). MySQL is never reachable outside
    | the Docker network (no published ports in docker-compose.prod.yml,
    | see docs/PRODUCTION_ARCHITECTURE.md) — skipping TLS for this
    | container-to-container connection relies on the exact same network
    | trust boundary the rest of this project already does, not a new one.
    | Revisit if MySQL ever moves outside the Docker network (e.g. RDS).
    |
    | The actual flag that means "disable TLS" differs by client flavor:
    | MariaDB's client (mariadb-dump/mariadb — the Docker image, see
    | infrastructure/docker/php/Dockerfile) uses the legacy boolean
    | `--skip-ssl`. The real Oracle MySQL 8.0 client (installed in CI,
    | see .github/workflows/ci.yml) REMOVED that legacy option entirely
    | — confirmed by hand, it errors "unknown option '--skip-ssl'" — and
    | only accepts `--ssl-mode=DISABLED`. Rather than guess which flavor
    | is running, the flag itself is configured per-environment, the
    | same way the binary names are.
    |
    */
    'skip_tls' => (bool) env('BACKUP_SKIP_TLS', true),
    'tls_disable_flag' => env('BACKUP_TLS_DISABLE_FLAG', '--skip-ssl'),

    /*
    |--------------------------------------------------------------------------
    | Timeouts (seconds)
    |--------------------------------------------------------------------------
    */
    'dump_timeout' => (int) env('BACKUP_DUMP_TIMEOUT', 3600),
    'restore_timeout' => (int) env('BACKUP_RESTORE_TIMEOUT', 3600),

    /*
    |--------------------------------------------------------------------------
    | Minimum viable dump size
    |--------------------------------------------------------------------------
    |
    | A real mariadb-dump of this schema is always comfortably larger than
    | this — schema DDL alone exceeds it. Anything smaller is treated as a
    | truncated/empty backup and fails loudly, even if mariadb-dump itself
    | exited 0.
    |
    */
    'minimum_size_bytes' => (int) env('BACKUP_MINIMUM_SIZE_BYTES', 512),

    /*
    |--------------------------------------------------------------------------
    | Verification
    |--------------------------------------------------------------------------
    |
    | backup:verify restores the dump into a throwaway database on the same
    | MySQL server to prove it is actually restorable, not just present —
    | see docs/BACKUPS_DISASTER_RECOVERY.md "Verification". That database's
    | name always carries this prefix, and backup:verify drops it again in
    | a finally block regardless of outcome, so it can never collide with
    | or linger alongside a real database. Requires a MySQL user with
    | CREATE/DROP DATABASE privilege — see that doc for the exact grant.
    |
    */
    'verification_database_prefix' => env('BACKUP_VERIFY_DB_PREFIX', 'raffleguru_backup_verify_'),

];
