<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        // P12.6 — off-server backup destination (see config/backup.php's
        // 'offsite' block and docs/BACKUPS_DISASTER_RECOVERY.md). A
        // deliberately SEPARATE disk/credential pair from 's3' above,
        // which this project reserves for any future user/media upload
        // feature — a compromised backup credential and a compromised
        // media credential are two different blast radii, never one.
        //
        // 'driver' => 's3' works against ANY S3-compatible API, not just
        // AWS — DigitalOcean Spaces, Cloudflare R2, Backblaze B2,
        // Wasabi, etc. (this project's local/CI test substitute is
        // adobe/s3mock — a real S3 API server, see
        // tests/Feature/Backup/Concerns/ConfiguresOffsiteTestStorage.php)
        // — by setting BACKUP_OFFSITE_ENDPOINT (+ path-style for
        // providers that need it). BACKUP_OFFSITE_REGION defaults to
        // "auto", which is what Cloudflare R2 expects; set a real AWS
        // region if using AWS S3 directly.
        //
        // 'throw' => true (unlike the stock disks above, which default
        // to false) is deliberate: a backup upload silently returning
        // false instead of throwing is exactly the kind of failure this
        // phase's "make upload failures visible and actionable"
        // requirement exists to prevent.
        //
        // 'options' applies server-side encryption (SSE) to every
        // object this disk writes — provider-side encryption at rest,
        // not application-side (see "Encryption" in
        // docs/BACKUPS_DISASTER_RECOVERY.md for exactly what this does
        // and does not protect against). 'retries'/'http' let the AWS
        // SDK itself retry a transient network failure before
        // BackupOffsiteService's own retry loop (config/backup.php
        // 'offsite.upload_attempts') even gets involved.
        'backups' => [
            'driver' => 's3',
            'key' => env('BACKUP_OFFSITE_KEY'),
            'secret' => env('BACKUP_OFFSITE_SECRET'),
            'region' => env('BACKUP_OFFSITE_REGION', 'auto'),
            'bucket' => env('BACKUP_OFFSITE_BUCKET'),
            'endpoint' => env('BACKUP_OFFSITE_ENDPOINT'),
            'use_path_style_endpoint' => (bool) env('BACKUP_OFFSITE_USE_PATH_STYLE_ENDPOINT', true),
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
            // array_filter drops this entirely when
            // BACKUP_OFFSITE_SSE_ALGORITHM is explicitly set to an
            // empty string — an escape hatch for the rare S3-compatible
            // provider that rejects ServerSideEncryption outright (e.g.
            // a bare MinIO instance with no KMS backend configured,
            // which errors "NotImplemented" — not a concern for this
            // project's own adobe/s3mock test substitute, confirmed by
            // hand to accept it with no extra configuration). Real
            // providers (AWS S3, DigitalOcean Spaces, Cloudflare R2)
            // support plain SSE-S3 (AES256) with no KMS required,
            // so production should leave this at its default.
            'options' => array_filter([
                'ServerSideEncryption' => env('BACKUP_OFFSITE_SSE_ALGORITHM', 'AES256'),
            ]),
            'retries' => (int) env('BACKUP_OFFSITE_SDK_RETRIES', 3),
            'http' => [
                'connect_timeout' => (int) env('BACKUP_OFFSITE_CONNECT_TIMEOUT', 10),
                'timeout' => (int) env('BACKUP_OFFSITE_TIMEOUT', 120),
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
