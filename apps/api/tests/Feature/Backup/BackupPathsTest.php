<?php

namespace Tests\Feature\Backup;

use App\Services\Backup\BackupPaths;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * P12.2 — naming/path behavior, pure and filesystem-free except for
 * ensureDirectoryExists(). No MySQL needed: runs in the fast SQLite job.
 */
class BackupPathsTest extends TestCase
{
    public function test_filename_includes_prefix_database_and_timestamp(): void
    {
        Config::set('backup.prefix', 'raffleguru');

        $paths = new BackupPaths;
        $filename = $paths->filenameFor('raffleguru', CarbonImmutable::create(2026, 3, 5, 2, 0, 0));

        $this->assertSame('raffleguru_raffleguru_20260305_020000.sql.gz', $filename);
    }

    public function test_meta_path_swaps_dump_extension_for_meta_extension(): void
    {
        Config::set('backup.path', '/tmp/does-not-matter');

        $paths = new BackupPaths;
        $metaPath = $paths->metaPath('raffleguru_raffleguru_20260305_020000.sql.gz');

        $this->assertSame('/tmp/does-not-matter/raffleguru_raffleguru_20260305_020000.meta.json', $metaPath);
    }

    public function test_owned_dump_filename_requires_the_exact_shape(): void
    {
        Config::set('backup.prefix', 'raffleguru');

        $paths = new BackupPaths;

        $this->assertTrue($paths->isOwnedDumpFilename('raffleguru_raffleguru_20260305_020000.sql.gz'));
        $this->assertFalse($paths->isOwnedDumpFilename('raffleguru_raffleguru_20260305_020000.sql'), 'wrong extension');
        $this->assertFalse($paths->isOwnedDumpFilename('something_else_20260305_020000.sql.gz'), 'wrong prefix');
        $this->assertFalse($paths->isOwnedDumpFilename('raffleguru_raffleguru.sql.gz'), 'missing timestamp');
        $this->assertFalse($paths->isOwnedDumpFilename('../../etc/passwd'), 'path traversal attempt');
        $this->assertFalse($paths->isOwnedDumpFilename('raffleguru_raffleguru_20260305_020000.sql.gz; rm -rf /'), 'shell metacharacters');
    }

    public function test_ensure_directory_exists_creates_a_private_directory(): void
    {
        $dir = sys_get_temp_dir().'/p122_paths_test_'.bin2hex(random_bytes(4));
        Config::set('backup.path', $dir);

        $this->assertDirectoryDoesNotExist($dir);

        (new BackupPaths)->ensureDirectoryExists();

        $this->assertDirectoryExists($dir);
        $this->assertSame('0750', substr(sprintf('%o', fileperms($dir)), -4));

        rmdir($dir);
    }

    public function test_list_owned_dump_files_ignores_unrelated_files_and_sorts_chronologically(): void
    {
        $dir = sys_get_temp_dir().'/p122_paths_test_'.bin2hex(random_bytes(4));
        mkdir($dir);
        Config::set('backup.path', $dir);
        Config::set('backup.prefix', 'raffleguru');

        touch("{$dir}/raffleguru_raffleguru_20260101_000000.sql.gz");
        touch("{$dir}/raffleguru_raffleguru_20260305_000000.sql.gz");
        touch("{$dir}/raffleguru_raffleguru_20260305_000000.meta.json");
        touch("{$dir}/.gitignore");
        touch("{$dir}/some-unrelated-file.txt");

        $files = (new BackupPaths)->listOwnedDumpFiles();

        $this->assertSame([
            "{$dir}/raffleguru_raffleguru_20260101_000000.sql.gz",
            "{$dir}/raffleguru_raffleguru_20260305_000000.sql.gz",
        ], $files);

        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $name) {
            unlink("{$dir}/{$name}");
        }
        rmdir($dir);
    }
}
