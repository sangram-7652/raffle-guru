<?php

namespace Tests\Feature\Backup;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * P12.2 — `backup:restore` against a REAL MySQL server: restores a real
 * backup of `raffleguru_testing` into a brand-new scratch database (never
 * the live one) and confirms the schema actually came back.
 *
 * Requires the same CREATE/DROP DATABASE grant as
 * BackupVerificationCommandTest — see that class's docblock.
 */
#[Group('mysql')]
class BackupRestoreCommandTest extends TestCase
{
    private string $dir;

    private string $scratchDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useIsolatedMysqlDatabase();

        $this->dir = sys_get_temp_dir().'/p122_restore_test_'.bin2hex(random_bytes(4));
        Config::set('backup.path', $this->dir);

        // Must fall under the same raffleguru_backup_verify_% grant as
        // BackupVerificationCommandTest — see that class's docblock.
        $this->scratchDatabase = 'raffleguru_backup_verify_restoretest'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        DB::connection('mysql')->statement("DROP DATABASE IF EXISTS `{$this->scratchDatabase}`");

        foreach (glob("{$this->dir}/*") ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    public function test_restores_a_real_backup_into_a_new_scratch_database(): void
    {
        $this->artisan('backup:run')->assertSuccessful();
        $dumpPath = (glob("{$this->dir}/*.sql.gz") ?: [])[0];

        $this->artisan('backup:restore', [
            'file' => basename($dumpPath),
            '--database' => $this->scratchDatabase,
            '--force' => true,
        ])->assertSuccessful();

        $tableCount = DB::connection('mysql')->table('information_schema.tables')
            ->where('table_schema', $this->scratchDatabase)
            ->count();

        $this->assertGreaterThan(0, $tableCount);

        $adminCountInLive = DB::connection('mysql')->table('admins')->count();
        $adminCountInScratch = DB::connection('mysql')
            ->table("{$this->scratchDatabase}.admins")
            ->count();

        $this->assertSame($adminCountInLive, $adminCountInScratch);
    }

    public function test_aborts_without_force_when_confirmation_is_declined(): void
    {
        $this->artisan('backup:run')->assertSuccessful();
        $dumpPath = (glob("{$this->dir}/*.sql.gz") ?: [])[0];

        $this->artisan('backup:restore', [
            'file' => basename($dumpPath),
            '--database' => $this->scratchDatabase,
        ])
            ->expectsConfirmation('Restore "'.basename($dumpPath).'" into database "'.$this->scratchDatabase.'"? Existing tables with the same name will be dropped and replaced per the dump\'s own statements.', 'no')
            ->assertFailed();

        $exists = DB::connection('mysql')->table('information_schema.schemata')
            ->where('schema_name', $this->scratchDatabase)
            ->exists();
        $this->assertFalse($exists);
    }
}
