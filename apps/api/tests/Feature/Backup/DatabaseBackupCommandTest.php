<?php

namespace Tests\Feature\Backup;

use App\Services\Backup\BackupFailedException;
use App\Services\Backup\DatabaseBackupService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * P12.2 — `backup:run` against a REAL MySQL server (the isolated
 * `raffleguru_testing` database — see TestCase::useIsolatedMysqlDatabase).
 * #[Group('mysql')]: SQLite's :memory: database has nothing a real
 * mariadb-dump/mysqldump binary could connect to, so this can't run in
 * the fast SQLite job — see docs/CI_TEST_INFRASTRUCTURE.md's existing
 * convention.
 */
#[Group('mysql')]
class DatabaseBackupCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useIsolatedMysqlDatabase();

        $this->dir = sys_get_temp_dir().'/p122_backup_run_test_'.bin2hex(random_bytes(4));
        Config::set('backup.path', $this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob("{$this->dir}/*") ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    public function test_creates_a_real_backup_with_valid_metadata(): void
    {
        $this->artisan('backup:run')->assertSuccessful();

        $dumps = glob("{$this->dir}/*.sql.gz") ?: [];
        $this->assertCount(1, $dumps);

        $dumpPath = $dumps[0];
        $metaPath = preg_replace('/\.sql\.gz$/', '.meta.json', $dumpPath);

        $this->assertFileExists($metaPath);
        $this->assertGreaterThan((int) config('backup.minimum_size_bytes'), filesize($dumpPath));

        $meta = json_decode((string) file_get_contents($metaPath), true);
        $this->assertSame('raffleguru_testing', $meta['database']);
        $this->assertSame('testing', $meta['environment']);
        $this->assertSame(filesize($dumpPath), $meta['size_bytes']);
        $this->assertSame(hash_file('sha256', $dumpPath), $meta['sha256']);

        // The dump is genuinely valid gzip, not a 0-byte or corrupt stub.
        $this->assertTrue(Process::run(['gzip', '-t', $dumpPath])->successful());
    }

    public function test_fails_loudly_and_leaves_no_partial_file_when_mysql_is_unreachable(): void
    {
        Config::set('database.connections.mysql.password', 'definitely-the-wrong-password');

        $this->expectException(BackupFailedException::class);

        try {
            $this->app->make(DatabaseBackupService::class)->run();
        } finally {
            $this->assertSame([], glob("{$this->dir}/*") ?: []);
        }
    }

    public function test_backup_run_command_also_prunes_backups_past_retention(): void
    {
        Config::set('backup.retention_days', 1);
        mkdir($this->dir, 0750, true);

        $oldDump = "{$this->dir}/raffleguru_raffleguru_testing_20200101_000000.sql.gz";
        file_put_contents($oldDump, 'stale dump content');
        touch($oldDump, now()->subDays(30)->getTimestamp());

        $this->artisan('backup:run')->assertSuccessful();

        $this->assertFileDoesNotExist($oldDump);
        $this->assertCount(1, glob("{$this->dir}/*.sql.gz") ?: []);
    }
}
