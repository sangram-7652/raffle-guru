<?php

namespace App\Console\Commands\Backup;

use App\Services\Backup\BackupRetentionService;
use Illuminate\Console\Command;

/**
 * `php artisan backup:prune` — standalone retention cleanup, in case an
 * operator wants to run it outside the daily `backup:run` cycle (which
 * calls this automatically after every successful backup). See
 * docs/BACKUPS_DISASTER_RECOVERY.md "Retention".
 */
class PruneBackupsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'backup:prune';

    /**
     * @var string
     */
    protected $description = 'Delete backup artifacts past the configured retention window.';

    public function handle(BackupRetentionService $retention): int
    {
        $deleted = $retention->prune();

        if ($deleted === []) {
            $this->info('No backups past the retention window.');

            return self::SUCCESS;
        }

        foreach ($deleted as $entry) {
            $this->line("Deleted: {$entry['filename']} ({$entry['size_bytes']} bytes)");
        }

        $this->info(sprintf('Deleted %d backup(s), %d byte(s) total.', count($deleted), array_sum(array_column($deleted, 'size_bytes'))));

        return self::SUCCESS;
    }
}
