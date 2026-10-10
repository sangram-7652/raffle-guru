<?php

namespace Tests\Feature\Backup\Offsite;

use App\Services\Backup\BackupOffsiteRetentionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Backup\Concerns\ConfiguresOffsiteTestStorage;
use Tests\TestCase;

/**
 * P12.6 — BackupOffsiteRetentionService against a real S3 API server
 * (adobe/s3mock, not a mock of this project's own code — see
 * tests/Feature/Backup/Concerns/ConfiguresOffsiteTestStorage.php).
 * Mirrors BackupRetentionServiceTest's (P12.2, local) scenarios, plus
 * the one guarantee that's unique to the offsite case: the single most
 * recent remote backup is never deleted, even when it's past the
 * retention window — see that service's docblock for why.
 * #[Group('mysql')]: needs that same S3 mock server — see
 * tests/Feature/Backup/Concerns/ConfiguresOffsiteTestStorage.php.
 */
#[Group('mysql')]
class BackupOffsiteRetentionTest extends TestCase
{
    use ConfiguresOffsiteTestStorage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureOffsiteTestStorage();
        Config::set('backup.offsite.retention_days', 14);
    }

    protected function tearDown(): void
    {
        $this->cleanOffsiteTestBucket();

        parent::tearDown();
    }

    private function seedRemoteBackup(string $filename, string $contents = 'fake dump content'): void
    {
        Storage::disk('backups')->put($filename, $contents);
        Storage::disk('backups')->put(str_replace('.sql.gz', '.meta.json', $filename), '{}');
    }

    public function test_deletes_only_objects_older_than_the_retention_window_keeping_the_newest(): void
    {
        $now = CarbonImmutable::create(2026, 3, 15);

        $this->seedRemoteBackup('raffleguru_raffleguru_20260101_000000.sql.gz');
        $this->seedRemoteBackup('raffleguru_raffleguru_20260310_000000.sql.gz'); // 5 days old, clearly inside the 14-day window

        $result = app(BackupOffsiteRetentionService::class)->prune($now);

        $this->assertSame(['raffleguru_raffleguru_20260101_000000.sql.gz'], array_column($result['deleted'], 'filename'));
        $this->assertFalse(Storage::disk('backups')->exists('raffleguru_raffleguru_20260101_000000.sql.gz'));
        $this->assertFalse(Storage::disk('backups')->exists('raffleguru_raffleguru_20260101_000000.meta.json'));
        $this->assertTrue(Storage::disk('backups')->exists('raffleguru_raffleguru_20260310_000000.sql.gz'));
        $this->assertFalse($result['newest_past_retention']);
    }

    public function test_never_deletes_the_single_newest_object_even_when_past_retention(): void
    {
        $now = CarbonImmutable::create(2026, 3, 15);

        // The ONLY backup that exists, and it's 400 days old — far past
        // any reasonable retention window. Simulates offsite uploads
        // having silently failed for a long time.
        $this->seedRemoteBackup('raffleguru_raffleguru_20250101_000000.sql.gz');

        $result = app(BackupOffsiteRetentionService::class)->prune($now);

        $this->assertSame([], $result['deleted']);
        $this->assertTrue(Storage::disk('backups')->exists('raffleguru_raffleguru_20250101_000000.sql.gz'));
        $this->assertTrue($result['newest_past_retention']);
        $this->assertSame('raffleguru_raffleguru_20250101_000000.sql.gz', $result['newest_filename']);
    }

    public function test_never_touches_an_object_outside_the_owned_naming_pattern(): void
    {
        $now = CarbonImmutable::create(2026, 3, 15);

        $this->seedRemoteBackup('raffleguru_raffleguru_20260301_000000.sql.gz');
        Storage::disk('backups')->put('operator-notes/readme.txt', 'do not delete me');
        Storage::disk('backups')->put('some-unrelated-ancient-file.sql.gz', 'not ours');

        app(BackupOffsiteRetentionService::class)->prune($now);

        $this->assertTrue(Storage::disk('backups')->exists('operator-notes/readme.txt'));
        $this->assertTrue(Storage::disk('backups')->exists('some-unrelated-ancient-file.sql.gz'));
    }

    public function test_ages_by_the_filenames_own_timestamp_not_upload_time(): void
    {
        $now = CarbonImmutable::create(2026, 3, 15);

        // Uploaded "now" (the server's own LastModified will be today), but
        // the filename's embedded timestamp says this backup was
        // actually taken a year ago — retention must honor the
        // filename, not when the bytes happened to land in the bucket
        // (e.g. a late/retried upload of an old backup).
        $this->seedRemoteBackup('raffleguru_raffleguru_20250101_000000.sql.gz');
        $this->seedRemoteBackup('raffleguru_raffleguru_20260301_000000.sql.gz');

        $result = app(BackupOffsiteRetentionService::class)->prune($now);

        $this->assertSame(['raffleguru_raffleguru_20250101_000000.sql.gz'], array_column($result['deleted'], 'filename'));
    }
}
