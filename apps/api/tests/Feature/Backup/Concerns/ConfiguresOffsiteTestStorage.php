<?php

namespace Tests\Feature\Backup\Concerns;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

/**
 * Points config('backup.offsite.*')/the 'backups' disk at a REAL
 * S3-compatible MinIO instance for the duration of one test — never a
 * mock of the filesystem contract. See docs/BACKUPS_DISASTER_RECOVERY.md
 * "Off-server backup (P12.6)" for how to run this MinIO instance
 * locally (the same container the CI "mysql" job starts as a service,
 * see .github/workflows/ci.yml) and phpunit.xml for the
 * BACKUP_OFFSITE_TEST_* defaults this reads.
 *
 * `BACKUP_OFFSITE_TEST_BUCKET` is a dedicated, disposable test bucket —
 * cleanOffsiteTestBucket() deletes every object in it (called from both
 * setUp and tearDown, defensively, in case a previous failed run left
 * litter behind) and is safe ONLY because this bucket exists for no
 * other purpose. Never point this at a real production bucket.
 */
trait ConfiguresOffsiteTestStorage
{
    protected function configureOffsiteTestStorage(): void
    {
        Config::set('backup.offsite.enabled', true);
        Config::set('filesystems.disks.backups', [
            'driver' => 's3',
            'key' => (string) env('BACKUP_OFFSITE_TEST_KEY', 'raffleguru_backup_test'),
            'secret' => (string) env('BACKUP_OFFSITE_TEST_SECRET', 'raffleguru_backup_test_secret'),
            'region' => 'auto',
            'bucket' => (string) env('BACKUP_OFFSITE_TEST_BUCKET', 'raffleguru-backups-test'),
            'endpoint' => (string) env('BACKUP_OFFSITE_TEST_ENDPOINT', 'http://127.0.0.1:9000'),
            'use_path_style_endpoint' => true,
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
            // No ServerSideEncryption here: the MinIO test substitute
            // has no KMS backend configured and rejects SSE with
            // "NotImplemented" — see config/filesystems.php's 'backups'
            // disk comment. Production is unaffected; it reads that
            // config array, not this one.
            'options' => [],
        ]);

        // Safe to call this method more than once per test (e.g. to
        // restore a deliberately-broken endpoint — see
        // BackupOffsiteUploadTest's breakOffsiteEndpoint()/
        // restoreOffsiteEndpoint()): FilesystemManager caches a
        // resolved disk by name, so without forgetting it here, a
        // second call's Config::set() above would have no effect on
        // the already-cached instance.
        Storage::forgetDisk('backups');

        $this->cleanOffsiteTestBucket();
    }

    protected function cleanOffsiteTestBucket(): void
    {
        $disk = Storage::disk('backups');

        foreach ($disk->allFiles() as $path) {
            $disk->delete($path);
        }
    }
}
