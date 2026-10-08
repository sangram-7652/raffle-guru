<?php

namespace Tests\Feature\Backup;

use App\Services\Backup\BackupRetentionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * P12.2 — retention cleanup. Pure filesystem logic against a disposable
 * temp directory, no MySQL needed: runs in the fast SQLite job.
 */
class BackupRetentionServiceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/p122_retention_test_'.bin2hex(random_bytes(4));
        mkdir($this->dir);

        Config::set('backup.path', $this->dir);
        Config::set('backup.prefix', 'raffleguru');
        Config::set('backup.retention_days', 14);
    }

    protected function tearDown(): void
    {
        foreach (array_diff(scandir($this->dir) ?: [], ['.', '..']) as $name) {
            @unlink("{$this->dir}/{$name}");
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    private function createBackupFile(string $filename, CarbonImmutable $mtime): void
    {
        $path = "{$this->dir}/{$filename}";
        file_put_contents($path, 'fake dump content');
        touch($path, $mtime->getTimestamp());
    }

    public function test_deletes_only_files_older_than_the_retention_window(): void
    {
        $now = CarbonImmutable::create(2026, 3, 15);

        $this->createBackupFile('raffleguru_raffleguru_20260101_000000.sql.gz', $now->subDays(40));
        $this->createBackupFile('raffleguru_raffleguru_20260101_000000.meta.json', $now->subDays(40));
        $this->createBackupFile('raffleguru_raffleguru_20260310_000000.sql.gz', $now->subDays(5));

        $deleted = (app(BackupRetentionService::class))->prune($now);

        $this->assertSame(['raffleguru_raffleguru_20260101_000000.sql.gz'], array_column($deleted, 'filename'));
        $this->assertFileDoesNotExist("{$this->dir}/raffleguru_raffleguru_20260101_000000.sql.gz");
        $this->assertFileDoesNotExist("{$this->dir}/raffleguru_raffleguru_20260101_000000.meta.json");
        $this->assertFileExists("{$this->dir}/raffleguru_raffleguru_20260310_000000.sql.gz");
    }

    public function test_never_deletes_a_file_that_does_not_match_the_owned_filename_shape(): void
    {
        $now = CarbonImmutable::create(2026, 3, 15);

        $this->createBackupFile('some-unrelated-ancient-file.sql.gz', $now->subDays(400));
        file_put_contents("{$this->dir}/.gitignore", '*');
        touch("{$this->dir}/.gitignore", $now->subDays(400)->getTimestamp());

        $deleted = (app(BackupRetentionService::class))->prune($now);

        $this->assertSame([], $deleted);
        $this->assertFileExists("{$this->dir}/some-unrelated-ancient-file.sql.gz");
        $this->assertFileExists("{$this->dir}/.gitignore");
    }

    public function test_reports_what_was_deleted_including_size(): void
    {
        $now = CarbonImmutable::create(2026, 3, 15);

        $this->createBackupFile('raffleguru_raffleguru_20260101_000000.sql.gz', $now->subDays(40));

        $deleted = (app(BackupRetentionService::class))->prune($now);

        $this->assertCount(1, $deleted);
        $this->assertSame('raffleguru_raffleguru_20260101_000000.sql.gz', $deleted[0]['filename']);
        $this->assertSame(strlen('fake dump content'), $deleted[0]['size_bytes']);
    }

    public function test_fails_safely_when_the_backup_directory_does_not_exist(): void
    {
        Config::set('backup.path', $this->dir.'/does-not-exist');

        $deleted = (app(BackupRetentionService::class))->prune();

        $this->assertSame([], $deleted);
    }
}
