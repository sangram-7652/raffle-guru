<?php

namespace App\Console\Commands\Backup;

use App\Services\Backup\BackupOffsiteException;
use App\Services\Backup\BackupOffsiteRetentionService;
use App\Services\Backup\BackupOffsiteService;
use App\Services\Backup\BackupPaths;
use Illuminate\Console\Command;

/**
 * `php artisan backup:upload-offsite {file?}` — uploads a local backup
 * (defaulting to the most recent one, same convention as
 * `backup:verify`) to off-server storage and proves the upload is
 * intact. See docs/BACKUPS_DISASTER_RECOVERY.md "Off-server backup
 * (P12.6)". Chained automatically by `backup:run` when
 * BACKUP_OFFSITE_ENABLED=true (see RunDatabaseBackupCommand) — also
 * exposed standalone so an operator can re-upload a specific past
 * backup without retaking it.
 */
class UploadOffsiteBackupCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'backup:upload-offsite {file? : Backup filename to upload; defaults to the most recent local backup}';

    /**
     * @var string
     */
    protected $description = 'Upload a local backup to off-server storage and verify it arrived intact.';

    public function handle(BackupOffsiteService $offsite, BackupOffsiteRetentionService $offsiteRetention, BackupPaths $paths): int
    {
        $file = $this->argument('file');

        if ($file === null) {
            $owned = $paths->listOwnedDumpFiles();

            if ($owned === []) {
                $this->error('No local backups found — run backup:run first.');

                return self::FAILURE;
            }

            $file = basename($owned[array_key_last($owned)]);
        } else {
            $file = basename($file);
        }

        try {
            $result = $offsite->upload($file);
        } catch (BackupOffsiteException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Offsite upload verified: %s (%d bytes, sha256 %s)%s.',
            $result['filename'],
            $result['size_bytes'],
            substr($result['sha256'], 0, 12).'…',
            $result['already_present'] ? ' — already present remotely, re-verified' : '',
        ));

        try {
            $result = $offsiteRetention->prune();
        } catch (BackupOffsiteException $e) {
            // The upload above already succeeded and was verified — a
            // failure to prune old remote backups afterwards is a
            // separate, lower-severity problem and must not make this
            // command report the upload itself as failed.
            $this->warn("Offsite retention cleanup failed (upload above still succeeded): {$e->getMessage()}");

            return self::SUCCESS;
        }

        if ($result['deleted'] !== []) {
            $this->info(sprintf('Pruned %d offsite backup(s) past the retention window.', count($result['deleted'])));
        }

        return self::SUCCESS;
    }
}
