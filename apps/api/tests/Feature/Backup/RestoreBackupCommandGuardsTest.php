<?php

namespace Tests\Feature\Backup;

use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * P12.2 — production-safety guards on `backup:restore`. Every case here
 * is rejected before the command ever touches a file or a database
 * connection, so no MySQL is needed: runs in the fast SQLite job.
 */
class RestoreBackupCommandGuardsTest extends TestCase
{
    public function test_refuses_without_a_database_option(): void
    {
        $this->artisan('backup:restore', ['file' => 'raffleguru_raffleguru_20260101_000000.sql.gz'])
            ->assertFailed()
            ->expectsOutputToContain('requires --database');
    }

    public function test_refuses_to_target_the_live_database_without_the_extra_confirmation_flag(): void
    {
        Config::set('database.connections.mysql.database', 'raffleguru_production');

        $this->artisan('backup:restore', [
            'file' => 'raffleguru_raffleguru_20260101_000000.sql.gz',
            '--database' => 'raffleguru_production',
        ])
            ->assertFailed()
            ->expectsOutputToContain('confirm-production-overwrite');
    }

    public function test_refuses_a_filename_that_does_not_look_like_an_owned_backup(): void
    {
        $this->artisan('backup:restore', [
            'file' => '../../etc/passwd',
            '--database' => 'some_scratch_db',
        ])
            ->assertFailed()
            ->expectsOutputToContain("doesn't look like a backup");
    }

    public function test_refuses_a_nonexistent_but_well_formed_backup_filename(): void
    {
        $this->artisan('backup:restore', [
            'file' => 'raffleguru_raffleguru_20260101_000000.sql.gz',
            '--database' => 'some_scratch_db',
        ])
            ->assertFailed()
            ->expectsOutputToContain('does not exist');
    }
}
