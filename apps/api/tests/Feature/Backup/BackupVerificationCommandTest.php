<?php

namespace Tests\Feature\Backup;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * P12.2 — `backup:verify` against a REAL MySQL server: proves a real
 * backup of `raffleguru_testing` is actually restorable by restoring it
 * into a throwaway database and dropping it again, and that a
 * corrupt/truncated artifact is caught before any restore is attempted.
 *
 * Requires the CI/local MySQL user to have CREATE/DROP DATABASE on
 * `raffleguru_backup_verify_%` — see .github/workflows/ci.yml's "Grant
 * backup verification database privilege" step and
 * docs/BACKUPS_DISASTER_RECOVERY.md "Verification" for the equivalent
 * one-time production/local grant.
 */
#[Group('mysql')]
class BackupVerificationCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useIsolatedMysqlDatabase();

        $this->dir = sys_get_temp_dir().'/p122_verify_test_'.bin2hex(random_bytes(4));
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

    public function test_verifies_a_real_backup_by_restoring_it_into_a_throwaway_database(): void
    {
        $this->artisan('backup:run')->assertSuccessful();

        $this->artisan('backup:verify')
            ->assertSuccessful()
            ->expectsOutputToContain('Verified:');

        // The throwaway verification database must not linger afterward.
        $leftover = DB::connection('mysql')->select(
            "SHOW DATABASES LIKE 'raffleguru_backup_verify_%'",
        );
        $this->assertSame([], $leftover);
    }

    public function test_verify_never_touches_the_live_database(): void
    {
        $before = DB::connection('mysql')->table('admins')->count();

        $this->artisan('backup:run')->assertSuccessful();
        $this->artisan('backup:verify')->assertSuccessful();

        $this->assertSame($before, DB::connection('mysql')->table('admins')->count());
    }

    public function test_rejects_a_truncated_backup_without_needing_a_restore(): void
    {
        $this->artisan('backup:run')->assertSuccessful();

        $dumpPath = (glob("{$this->dir}/*.sql.gz") ?: [])[0];
        $original = (string) file_get_contents($dumpPath);
        // Keep enough bytes to clear the minimum-size check (so this
        // actually exercises the gzip integrity check, not the earlier
        // size check) while still being an incomplete gzip stream.
        $this->assertGreaterThan(600, strlen($original), 'test assumption: a real schema dump exceeds 600 bytes');
        file_put_contents($dumpPath, substr($original, 0, 600));

        $this->artisan('backup:verify', ['file' => basename($dumpPath)])
            ->assertFailed()
            ->expectsOutputToContain('gzip integrity');
    }

    public function test_rejects_an_empty_file(): void
    {
        $filename = 'raffleguru_raffleguru_testing_20260101_000000.sql.gz';
        mkdir($this->dir, 0750, true);
        file_put_contents("{$this->dir}/{$filename}", '');

        $this->artisan('backup:verify', ['file' => $filename])
            ->assertFailed()
            ->expectsOutputToContain('truncated');
    }
}
