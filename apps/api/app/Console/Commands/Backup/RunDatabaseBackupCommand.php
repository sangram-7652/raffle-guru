<?php

namespace App\Console\Commands\Backup;

use App\Services\Backup\BackupFailedException;
use App\Services\Backup\BackupRetentionService;
use App\Services\Backup\DatabaseBackupService;
use Illuminate\Console\Command;

/**
 * `php artisan backup:run` — scheduled daily (routes/console.php, P12.2).
 * Takes a logical MySQL backup, then prunes anything past the configured
 * retention window. See docs/BACKUPS_DISASTER_RECOVERY.md.
 *
 * Any failure propagates as an uncaught exception (not swallowed into a
 * console message) so it reaches the normal exception-report pipeline —
 * and therefore Sentry in an environment that has it configured, see
 * P12.1 — and the command exits non-zero either way.
 */
class RunDatabaseBackupCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'backup:run';

    /**
     * @var string
     */
    protected $description = 'Take a logical MySQL backup and prune backups past the retention window.';

    public function handle(DatabaseBackupService $backups, BackupRetentionService $retention): int
    {
        try {
            $artifact = $backups->run();
        } catch (BackupFailedException $e) {
            // DatabaseBackupService already logged the full failure
            // context (Log::error, see backup.run.failed /
            // backup.run.truncated) — this is just the operator-facing
            // surface, matching CompetitionDrawCommand's convention of
            // a clean error message + non-zero exit rather than a raw
            // exception trace.
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Backup created: {$artifact->filename} ({$artifact->sizeBytes} bytes).");

        $deleted = $retention->prune();

        if ($deleted !== []) {
            $this->info(sprintf('Pruned %d backup(s) past the retention window.', count($deleted)));
        }

        return self::SUCCESS;
    }
}
