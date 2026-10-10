<?php

namespace Tests\Feature\Backup\Offsite;

use App\Services\Backup\BackupOffsiteException;
use App\Services\Backup\BackupOffsiteService;
use App\Services\Backup\BackupPaths;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Backup\Concerns\ConfiguresOffsiteTestStorage;
use Tests\TestCase;

/**
 * P12.6 — BackupOffsiteService::upload() against a REAL S3-compatible
 * MinIO instance, never a mock of the filesystem contract — same "prove
 * it, don't assume it" posture as backup:verify's real restore (P12.2).
 * #[Group('mysql')]: needs the MinIO service CI starts alongside its
 * real MySQL server for the "mysql" job — see
 * tests/Feature/Backup/Concerns/ConfiguresOffsiteTestStorage.php and
 * .github/workflows/ci.yml.
 */
#[Group('mysql')]
class BackupOffsiteUploadTest extends TestCase
{
    use ConfiguresOffsiteTestStorage;

    private string $dir;

    private BackupPaths $paths;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureOffsiteTestStorage();

        $this->dir = sys_get_temp_dir().'/p126_offsite_upload_test_'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0750, true);
        Config::set('backup.path', $this->dir);

        $this->paths = app(BackupPaths::class);
    }

    protected function tearDown(): void
    {
        $this->cleanOffsiteTestBucket();

        foreach (array_diff(scandir($this->dir) ?: [], ['.', '..']) as $name) {
            @unlink("{$this->dir}/{$name}");
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    /**
     * @return array{dump: string, filename: string}
     */
    private function createLocalBackup(string $filename, string $contents = 'a real-enough fake backup payload for integrity checks'): array
    {
        $dumpPath = $this->paths->dumpPath($filename);
        file_put_contents($dumpPath, $contents);

        $metaPath = $this->paths->metaPath($filename);
        file_put_contents($metaPath, json_encode([
            'filename' => $filename,
            'database' => 'raffleguru_testing',
            'sha256' => hash_file('sha256', $dumpPath),
            'size_bytes' => filesize($dumpPath),
        ]));

        return ['dump' => $dumpPath, 'filename' => $filename];
    }

    public function test_uploads_and_verifies_a_real_backup(): void
    {
        $filename = 'raffleguru_raffleguru_testing_20260301_020000.sql.gz';
        $this->createLocalBackup($filename);

        $result = app(BackupOffsiteService::class)->upload($filename);

        $this->assertSame($filename, $result['filename']);
        $this->assertFalse($result['already_present']);
        $this->assertTrue(Storage::disk('backups')->exists($filename));
        $this->assertTrue(Storage::disk('backups')->exists($this->paths->withMetaExtension($filename)));
        $this->assertSame(hash_file('sha256', $this->paths->dumpPath($filename)), $result['sha256']);
    }

    public function test_refuses_when_offsite_is_disabled(): void
    {
        Config::set('backup.offsite.enabled', false);

        $filename = 'raffleguru_raffleguru_testing_20260301_020000.sql.gz';
        $this->createLocalBackup($filename);

        $this->expectException(BackupOffsiteException::class);
        $this->expectExceptionMessage('Off-server backup is not enabled');

        app(BackupOffsiteService::class)->upload($filename);
    }

    public function test_refuses_a_filename_that_does_not_look_like_an_owned_backup(): void
    {
        $this->expectException(BackupOffsiteException::class);
        $this->expectExceptionMessage("doesn't look like a backup this app produced");

        app(BackupOffsiteService::class)->upload('../../etc/passwd');
    }

    public function test_refuses_a_missing_local_file(): void
    {
        $filename = 'raffleguru_raffleguru_testing_20260301_020000.sql.gz';

        $this->expectException(BackupOffsiteException::class);
        $this->expectExceptionMessage('does not exist');

        app(BackupOffsiteService::class)->upload($filename);
    }

    public function test_is_idempotent_for_a_duplicate_execution(): void
    {
        $filename = 'raffleguru_raffleguru_testing_20260301_020000.sql.gz';
        $this->createLocalBackup($filename);

        $first = app(BackupOffsiteService::class)->upload($filename);
        $this->assertFalse($first['already_present']);

        // Re-running for the exact same file is safe and still proves
        // integrity (re-verified, not just "trusted because it's
        // already there") — it just skips re-uploading the bytes.
        $second = app(BackupOffsiteService::class)->upload($filename);
        $this->assertTrue($second['already_present']);
        $this->assertSame($first['sha256'], $second['sha256']);
    }

    /**
     * Same byte length as the real content, different bytes —
     * remoteSizeMatches() alone would wrongly call this "already
     * uploaded, nothing to do"; only the download-and-rehash step
     * catches it. With more than one attempt available (the default),
     * the next attempt re-checks, finds the SAME size again, but this
     * time it's attempt 2 following a deletion — exists() now reports
     * false, so it re-uploads the correct local bytes and verification
     * passes: a transient/corrupted remote copy self-heals via the
     * same retry loop a genuine network blip would use, with no
     * separate "repair" code path.
     */
    public function test_detects_and_self_heals_a_remote_corruption_via_retry(): void
    {
        $filename = 'raffleguru_raffleguru_testing_20260301_020000.sql.gz';
        $contents = 'exactly-32-bytes-of-real-content';
        $this->createLocalBackup($filename, $contents);

        $corrupted = str_repeat('x', strlen($contents));
        Storage::disk('backups')->put($filename, $corrupted);

        $result = app(BackupOffsiteService::class)->upload($filename);

        $this->assertSame(hash_file('sha256', $this->paths->dumpPath($filename)), $result['sha256']);
        $this->assertSame($contents, Storage::disk('backups')->get($filename));
    }

    /**
     * The same corruption, but with retries exhausted after a single
     * attempt (upload_attempts=1) — isolates the "give up and clean up"
     * half of the behavior from the self-healing half tested above.
     */
    public function test_detects_a_remote_corruption_and_fails_cleanly_when_retries_are_exhausted(): void
    {
        Config::set('backup.offsite.upload_attempts', 1);

        $filename = 'raffleguru_raffleguru_testing_20260301_020000.sql.gz';
        $contents = 'exactly-32-bytes-of-real-content';
        $this->createLocalBackup($filename, $contents);

        $corrupted = str_repeat('x', strlen($contents));
        Storage::disk('backups')->put($filename, $corrupted);

        $this->expectException(BackupOffsiteException::class);
        $this->expectExceptionMessage('failed remote integrity verification');

        try {
            app(BackupOffsiteService::class)->upload($filename);
        } finally {
            // The corrupt object must not be left behind under a
            // filename that looks legitimate.
            $this->assertFalse(Storage::disk('backups')->exists($filename));
        }
    }

    /**
     * Points the 'backups' disk at an unreachable endpoint, forgetting
     * FilesystemManager's cached resolution (setUp()'s
     * cleanOffsiteTestBucket() already resolved 'backups' once against
     * the real MinIO endpoint, so without forgetting it a later config
     * change would have no effect on the cached instance).
     */
    private function breakOffsiteEndpoint(): void
    {
        Config::set('filesystems.disks.backups.endpoint', 'http://127.0.0.1:1');
        Storage::forgetDisk('backups');
    }

    /**
     * Undoes breakOffsiteEndpoint() — tearDown()'s own
     * cleanOffsiteTestBucket() call needs a working connection, and a
     * test that deliberately broke the endpoint must restore it before
     * returning rather than leaving tearDown to fail on an unrelated
     * assertion.
     */
    private function restoreOffsiteEndpoint(): void
    {
        $this->configureOffsiteTestStorage();
    }

    /**
     * Distinct from an unreachable endpoint: the network/server is
     * fine, but the credential itself is wrong — a different failure
     * mode (authentication, not connectivity) that must fail just as
     * cleanly.
     */
    public function test_fails_cleanly_when_credentials_are_missing_or_invalid(): void
    {
        Config::set('backup.offsite.upload_attempts', 1);
        Config::set('filesystems.disks.backups.key', 'not-a-real-key');
        Config::set('filesystems.disks.backups.secret', 'not-a-real-secret');
        Storage::forgetDisk('backups');

        $filename = 'raffleguru_raffleguru_testing_20260301_020000.sql.gz';
        $this->createLocalBackup($filename);

        try {
            app(BackupOffsiteService::class)->upload($filename);
            $this->fail('Expected BackupOffsiteException was not thrown.');
        } catch (BackupOffsiteException $e) {
            $this->assertStringNotContainsString('not-a-real-secret', $e->getMessage());
        } finally {
            $this->restoreOffsiteEndpoint();
        }
    }

    public function test_fails_cleanly_and_does_not_leak_the_secret_when_remote_storage_is_unreachable(): void
    {
        Config::set('backup.offsite.upload_attempts', 1);
        Config::set('filesystems.disks.backups.secret', 'super-secret-value-must-never-leak');
        $this->breakOffsiteEndpoint();

        $filename = 'raffleguru_raffleguru_testing_20260301_020000.sql.gz';
        $this->createLocalBackup($filename);

        try {
            app(BackupOffsiteService::class)->upload($filename);
            $this->fail('Expected BackupOffsiteException was not thrown.');
        } catch (BackupOffsiteException $e) {
            $this->assertStringNotContainsString('super-secret-value-must-never-leak', $e->getMessage());
        } finally {
            $this->restoreOffsiteEndpoint();
        }
    }

    public function test_retries_before_giving_up_on_a_persistent_failure(): void
    {
        Config::set('backup.offsite.upload_attempts', 2);
        Config::set('backup.offsite.retry_delay_ms', 0);
        $this->breakOffsiteEndpoint();

        Log::spy();

        $filename = 'raffleguru_raffleguru_testing_20260301_020000.sql.gz';
        $this->createLocalBackup($filename);

        try {
            app(BackupOffsiteService::class)->upload($filename);
            $this->fail('Expected BackupOffsiteException was not thrown.');
        } catch (BackupOffsiteException) {
            // expected
        } finally {
            $this->restoreOffsiteEndpoint();
        }

        // retry()'s $when callback (where the warning is logged) only
        // runs for an attempt that will actually be retried — the
        // FINAL attempt (2 of 2) rethrows immediately without calling
        // it. 2 attempts means exactly 1 intermediate failure logged.
        Log::shouldHaveReceived('warning')
            ->with('backup.offsite_upload.attempt_failed', \Mockery::any())
            ->once();
    }
}
