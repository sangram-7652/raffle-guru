<?php

namespace App\Console\Commands\Backup;

use App\Services\Backup\BackupVerificationException;
use App\Services\Backup\BackupVerificationService;
use Illuminate\Console\Command;

/**
 * `php artisan backup:verify {file?}` — proves the latest backup (or a
 * named one) is actually restorable, by restoring it into a throwaway
 * database and dropping it again. See
 * docs/BACKUPS_DISASTER_RECOVERY.md "Verification" — this NEVER touches
 * the live application database.
 */
class VerifyBackupCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'backup:verify {file? : Backup filename to verify; defaults to the most recent backup}';

    /**
     * @var string
     */
    protected $description = 'Verify a backup is restorable (gzip integrity + a real restore into a throwaway database).';

    public function handle(BackupVerificationService $verification): int
    {
        try {
            $result = $verification->verify($this->argument('file'));
        } catch (BackupVerificationException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Verified: %s (%d bytes, restored %d table(s) into a throwaway database).',
            $result['filename'],
            $result['size_bytes'],
            $result['tables_restored'],
        ));

        return self::SUCCESS;
    }
}
