<?php

namespace Tests\Feature\Backup;

use App\Services\Backup\BackupFailedException;
use App\Services\Backup\DatabaseBackupService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * P12.2 — the mariadb-dump/mysqldump binary name AND the TLS-disable
 * flag are both configured per-environment (config/backup.php), not
 * hardcoded, because the two client flavors this app actually runs
 * against (mariadb-client in Docker, the real Oracle MySQL client in
 * CI — see .github/workflows/ci.yml) don't agree on either: confirmed
 * by hand after CI's first real run failed with
 * `mysqldump: [ERROR] unknown option '--skip-ssl'` — Oracle's client
 * removed that legacy flag entirely and only accepts
 * `--ssl-mode=DISABLED`. This test locks in that the configured values
 * are what actually reach the process, not a hardcoded flag/binary
 * name that would silently drift back out of sync with either client.
 * Process::fake() means no real binary is needed: runs in the fast
 * SQLite job.
 */
class BackupCommandBuildingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.default', 'mysql');
        Config::set('database.connections.mysql.host', '127.0.0.1');
        Config::set('database.connections.mysql.port', '3306');
        Config::set('database.connections.mysql.database', 'raffleguru_testing');
        Config::set('database.connections.mysql.username', 'raffleguru');
        Config::set('database.connections.mysql.password', 'irrelevant');

        Config::set('backup.path', sys_get_temp_dir().'/p122_cmdbuild_test_'.bin2hex(random_bytes(4)));

        Process::fake();
    }

    public function test_dump_command_uses_the_configured_binary_and_tls_flag(): void
    {
        Config::set('backup.dump_binary', 'mysqldump');
        Config::set('backup.tls_disable_flag', '--ssl-mode=DISABLED');

        try {
            $this->app->make(DatabaseBackupService::class)->run();
        } catch (BackupFailedException) {
            // Expected under Process::fake() (see
            // DatabaseBackupSecretHandlingTest) — this test only cares
            // about the command that was dispatched.
        }

        Process::assertRan(function ($process) {
            $command = (array) $process->command;

            return $command[0] === 'mysqldump'
                && in_array('--ssl-mode=DISABLED', $command, true)
                && ! in_array('--skip-ssl', $command, true);
        });
    }

    public function test_dump_command_defaults_to_mariadb_naming(): void
    {
        try {
            $this->app->make(DatabaseBackupService::class)->run();
        } catch (BackupFailedException) {
        }

        Process::assertRan(function ($process) {
            $command = (array) $process->command;

            return $command[0] === 'mariadb-dump'
                && in_array('--skip-ssl', $command, true);
        });
    }
}
