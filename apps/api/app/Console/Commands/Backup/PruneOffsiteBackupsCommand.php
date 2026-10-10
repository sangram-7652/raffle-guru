<?php

namespace App\Console\Commands\Backup;

use App\Services\Backup\BackupOffsiteException;
use App\Services\Backup\BackupOffsiteRetentionService;
use Illuminate\Console\Command;

/**
 * `php artisan backup:prune-offsite` — standalone off-server retention
 * cleanup, for manual/operational use outside the `backup:run` chain
 * (see BackupOffsiteRetentionService; `backup:upload-offsite` already
 * calls this automatically after every successful upload). See
 * docs/BACKUPS_DISASTER_RECOVERY.md "Off-server backup (P12.6)".
 *
 * Safe to run at any time, including after offsite uploads have been
 * failing for a while — BackupOffsiteRetentionService never deletes the
 * single most recent remote backup regardless of its age.
 */
class PruneOffsiteBackupsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'backup:prune-offsite';

    /**
     * @var string
     */
    protected $description = 'Delete off-server backup objects past the configured retention window.';

    public function handle(BackupOffsiteRetentionService $retention): int
    {
        try {
            $result = $retention->prune();
        } catch (BackupOffsiteException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $deleted = $result['deleted'];

        if ($deleted === []) {
            $this->info('No offsite backups past the retention window.');
        } else {
            foreach ($deleted as $entry) {
                $this->line("Deleted: {$entry['filename']} ({$entry['size_bytes']} bytes)");
            }

            $this->info(sprintf('Deleted %d offsite backup(s), %d byte(s) total.', count($deleted), array_sum(array_column($deleted, 'size_bytes'))));
        }

        if ($result['newest_past_retention']) {
            $this->warn(sprintf(
                'The most recent remote backup (%s) is already past the %d-day retention window and was kept anyway — this usually means offsite uploads have been failing. Check backup:upload-offsite / backup:run.',
                $result['newest_filename'],
                (int) config('backup.offsite.retention_days'),
            ));
        }

        return self::SUCCESS;
    }
}
