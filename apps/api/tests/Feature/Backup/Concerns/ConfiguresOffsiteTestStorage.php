<?php

namespace Tests\Feature\Backup\Concerns;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

/**
 * Points config('backup.offsite.*')/the 'backups' disk at a real
 * S3-API server for the duration of one test — adobe/s3mock, a
 * purpose-built S3 mock server (not a PHP-level mock of Laravel's
 * Filesystem contract: every test here makes a real HTTP call through
 * the real AWS SDK, against a real server implementing the real S3
 * REST API, exercising the exact same client code production traffic
 * would). See docs/BACKUPS_DISASTER_RECOVERY.md "Off-server backup
 * (P12.6)" for how to run it locally (the same image/steps the CI
 * "mysql" job uses, see .github/workflows/ci.yml) and phpunit.xml for
 * the BACKUP_OFFSITE_TEST_* defaults this reads.
 *
 * **One real gap, stated plainly**: s3mock never validates credentials
 * — by its own documented design, not a misconfiguration on this
 * project's part (it tells callers to "supply any dummy credentials").
 * A real cloud provider's authentication/authorization behavior is
 * therefore NOT exercised by this suite — see
 * `BackupOffsiteUploadTest`'s comment on why no "wrong credentials"
 * test exists here.
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
            'endpoint' => (string) env('BACKUP_OFFSITE_TEST_ENDPOINT', 'http://127.0.0.1:9090'),
            'use_path_style_endpoint' => true,
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
            // Unlike bare MinIO without a KMS backend (which rejects
            // this with "NotImplemented"), s3mock accepts
            // ServerSideEncryption with no extra configuration —
            // confirmed by hand, not assumed — so this exercises the
            // SAME default encryption option production actually uses
            // (config/filesystems.php's 'backups' disk), not a
            // test-only simplification of it.
            'options' => ['ServerSideEncryption' => 'AES256'],
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
