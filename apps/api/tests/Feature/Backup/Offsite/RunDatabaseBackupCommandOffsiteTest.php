<?php

namespace Tests\Feature\Backup\Offsite;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Backup\Concerns\ConfiguresOffsiteTestStorage;
use Tests\TestCase;

/**
 * P12.6 — `backup:run` chaining an offsite upload when
 * BACKUP_OFFSITE_ENABLED=true (RunDatabaseBackupCommand). Separate from
 * tests/Feature/Backup/DatabaseBackupCommandTest.php (P12.2), which
 * never enables offsite at all and proves the pre-P12.6 behavior is
 * completely unchanged when it's off. #[Group('mysql')]: needs a real
 * MySQL dump AND a real S3 API server (adobe/s3mock) — see
 * tests/Feature/Backup/Concerns/ConfiguresOffsiteTestStorage.php.
 */
#[Group('mysql')]
class RunDatabaseBackupCommandOffsiteTest extends TestCase
{
    use ConfiguresOffsiteTestStorage;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useIsolatedMysqlDatabase();
        $this->configureOffsiteTestStorage();

        $this->dir = sys_get_temp_dir().'/p126_backup_run_offsite_test_'.bin2hex(random_bytes(4));
        Config::set('backup.path', $this->dir);
    }

    protected function tearDown(): void
    {
        $this->cleanOffsiteTestBucket();

        foreach (glob("{$this->dir}/*") ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    public function test_backup_run_uploads_offsite_when_enabled(): void
    {
        $this->artisan('backup:run')
            ->expectsOutputToContain('Backup created:')
            ->expectsOutputToContain('Offsite upload verified:')
            ->assertSuccessful();

        $dumps = glob("{$this->dir}/*.sql.gz") ?: [];
        $this->assertCount(1, $dumps);

        $filename = basename($dumps[0]);
        $this->assertTrue(Storage::disk('backups')->exists($filename));
    }

    public function test_local_backup_is_not_rolled_back_when_offsite_upload_fails(): void
    {
        // Break the offsite connection AFTER the local backup would
        // succeed, by pointing it at a bucket that doesn't exist —
        // the local backup step runs first and is already done by the
        // time this matters.
        Config::set('filesystems.disks.backups.bucket', 'this-bucket-does-not-exist-at-all');
        Config::set('backup.offsite.upload_attempts', 1);
        Storage::forgetDisk('backups');

        try {
            $this->artisan('backup:run')
                ->expectsOutputToContain('Backup created:')
                ->expectsOutputToContain('Offsite upload failed')
                ->assertFailed();

            // The local backup itself is still there, untouched, fully
            // usable on its own — a broken offsite half never undoes it.
            $dumps = glob("{$this->dir}/*.sql.gz") ?: [];
            $this->assertCount(1, $dumps);
            $this->assertGreaterThan(0, filesize($dumps[0]));
        } finally {
            // tearDown()'s cleanOffsiteTestBucket() needs a bucket that
            // actually exists — restore it before returning.
            $this->configureOffsiteTestStorage();
        }
    }
}
