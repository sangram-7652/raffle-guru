<?php

namespace App\Console\Commands\Backup;

use App\Services\Backup\BackupOffsiteException;
use App\Services\Backup\BackupOffsiteService;
use Illuminate\Console\Command;

/**
 * `php artisan backup:download-offsite {file}` — step 3-4 of the
 * disaster recovery procedure (docs/BACKUPS_DISASTER_RECOVERY.md
 * "Disaster recovery procedure"): retrieve a specific backup from
 * off-server storage and verify its integrity, landing it in
 * BACKUP_PATH under its real filename so the EXISTING, unmodified
 * `backup:verify`/`backup:restore` commands can take over from there —
 * this command deliberately does neither of those itself, reusing the
 * already-guarded restore workflow rather than duplicating it.
 */
class DownloadOffsiteBackupCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'backup:download-offsite {file : Backup filename to download, as listed in offsite storage}';

    /**
     * @var string
     */
    protected $description = 'Download a backup from off-server storage into BACKUP_PATH and verify its integrity.';

    public function handle(BackupOffsiteService $offsite): int
    {
        $file = basename($this->argument('file'));

        try {
            $localPath = $offsite->download($file);
        } catch (BackupOffsiteException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Downloaded and verified: {$file} -> {$localPath}");
        $this->warn('Next: run `backup:verify '.$file.'` before `backup:restore '.$file.' --database=<target>` — see docs/BACKUPS_DISASTER_RECOVERY.md "Restore procedure".');

        return self::SUCCESS;
    }
}
