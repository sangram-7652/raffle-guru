<?php

namespace Tests\Feature\Backup\Offsite;

use App\Services\Backup\BackupOffsiteException;
use App\Services\Backup\BackupOffsiteService;
use App\Services\Backup\BackupPaths;
use App\Services\Backup\DatabaseBackupService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Backup\Concerns\ConfiguresOffsiteTestStorage;
use Tests\TestCase;

/**
 * P12.6 — BackupOffsiteService::download(), step 3-4 of the disaster
 * recovery procedure (docs/BACKUPS_DISASTER_RECOVERY.md "Disaster
 * recovery procedure"). #[Group('mysql')]: needs the real MinIO
 * instance — see
 * tests/Feature/Backup/Concerns/ConfiguresOffsiteTestStorage.php. The
 * last test here also proves the download integrates with the EXISTING,
 * completely unmodified backup:verify command — a real MySQL restore —
 * which is the other reason this needs the "mysql" job specifically.
 */
#[Group('mysql')]
class BackupOffsiteDownloadTest extends TestCase
{
    use ConfiguresOffsiteTestStorage;

    private string $dir;

    private BackupPaths $paths;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureOffsiteTestStorage();

        $this->dir = sys_get_temp_dir().'/p126_offsite_download_test_'.bin2hex(random_bytes(4));
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

    private function uploadAFreshBackup(string $filename, string $contents): void
    {
        $dumpPath = $this->paths->dumpPath($filename);
        file_put_contents($dumpPath, $contents);
        file_put_contents($this->paths->metaPath($filename), json_encode(['sha256' => hash_file('sha256', $dumpPath)]));

        app(BackupOffsiteService::class)->upload($filename);

        // Simulate the disaster: the local copies are gone, only the
        // remote ones remain — exactly what download() is for.
        @unlink($dumpPath);
        @unlink($this->paths->metaPath($filename));
    }

    public function test_downloads_and_verifies_into_backup_path(): void
    {
        $filename = 'raffleguru_raffleguru_testing_20260301_030000.sql.gz';
        $contents = str_repeat('real-dump-bytes-', 50);
        $this->uploadAFreshBackup($filename, $contents);

        $localPath = app(BackupOffsiteService::class)->download($filename);

        $this->assertSame($this->paths->dumpPath($filename), $localPath);
        $this->assertFileExists($localPath);
        $this->assertSame($contents, file_get_contents($localPath));
        $this->assertFileDoesNotExist($localPath.'.partial');
    }

    public function test_refuses_a_filename_that_does_not_look_like_an_owned_backup(): void
    {
        $this->expectException(BackupOffsiteException::class);
        $this->expectExceptionMessage("doesn't look like a backup this app produced");

        app(BackupOffsiteService::class)->download('../../etc/passwd');
    }

    public function test_refuses_when_remote_meta_is_missing(): void
    {
        $filename = 'raffleguru_raffleguru_testing_20260301_030000.sql.gz';

        // A dump with no .meta.json sidecar at all — nothing independent
        // to verify a download against.
        Storage::disk('backups')->put($filename, 'dump-with-no-metadata');

        $this->expectException(BackupOffsiteException::class);
        $this->expectExceptionMessage('No remote .meta.json found');

        app(BackupOffsiteService::class)->download($filename);
    }

    public function test_detects_checksum_mismatch_and_leaves_no_partial_file_under_the_real_name(): void
    {
        $filename = 'raffleguru_raffleguru_testing_20260301_030000.sql.gz';

        // The metadata claims a SHA-256 that the dump bytes don't
        // actually have — simulates the remote object having been
        // corrupted or swapped after the metadata was written.
        Storage::disk('backups')->put($filename, 'actual-dump-content');
        Storage::disk('backups')->put(
            $this->paths->withMetaExtension($filename),
            json_encode(['sha256' => hash('sha256', 'a completely different payload')]),
        );

        try {
            app(BackupOffsiteService::class)->download($filename);
            $this->fail('Expected BackupOffsiteException was not thrown.');
        } catch (BackupOffsiteException $e) {
            $this->assertStringContainsString('failed integrity verification', $e->getMessage());
        }

        $this->assertFileDoesNotExist($this->paths->dumpPath($filename));
        $this->assertFileDoesNotExist($this->paths->dumpPath($filename).'.partial');
    }

    public function test_downloaded_backup_passes_the_existing_unmodified_verify_command(): void
    {
        $this->useIsolatedMysqlDatabase();

        $filename = app(DatabaseBackupService::class)->run()->filename;
        app(BackupOffsiteService::class)->upload($filename);

        // Prove this is a REAL disaster-recovery download, not reusing
        // the local copy backup:run just created.
        @unlink($this->paths->dumpPath($filename));

        app(BackupOffsiteService::class)->download($filename);

        // backup:verify is completely unmodified by P12.6 — it has no
        // idea this file arrived via offsite download rather than a
        // local backup:run, which is exactly the point.
        $this->artisan('backup:verify', ['file' => $filename])->assertSuccessful();
    }
}
