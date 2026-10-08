<?php

namespace Tests\Feature\Backup;

use App\Services\Backup\BackupFailedException;
use App\Services\Backup\DatabaseBackupService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * P12.2 — the database password must never appear on the mariadb-dump
 * command line (visible to any other process on the host via `ps`); it
 * must only ever reach the process via the MYSQL_PWD environment
 * variable, which is private to that process. Verified by faking
 * Process entirely (no real binary, no MySQL) and inspecting exactly
 * what was dispatched — runs in the fast SQLite job.
 */
class DatabaseBackupSecretHandlingTest extends TestCase
{
    private const SECRET = 'super-secret-password-must-not-leak';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.default', 'mysql');
        Config::set('database.connections.mysql.host', '127.0.0.1');
        Config::set('database.connections.mysql.port', '3306');
        Config::set('database.connections.mysql.database', 'raffleguru_testing');
        Config::set('database.connections.mysql.username', 'raffleguru');
        Config::set('database.connections.mysql.password', self::SECRET);

        Config::set('backup.path', sys_get_temp_dir().'/p122_secret_test_'.bin2hex(random_bytes(4)));

        Process::fake();
    }

    public function test_password_is_passed_via_env_not_as_a_command_line_argument(): void
    {
        try {
            $this->app->make(DatabaseBackupService::class)->run();
        } catch (BackupFailedException) {
            // Expected: Process::fake() never invokes the streaming output
            // callback, so the dump is empty and DatabaseBackupService
            // correctly rejects it as truncated. This test only cares
            // about what command/env were dispatched before that happened.
        }

        Process::assertRan(function ($process) {
            $command = implode(' ', (array) $process->command);

            return ! str_contains($command, self::SECRET)
                && ($process->environment['MYSQL_PWD'] ?? null) === self::SECRET;
        });
    }
}
