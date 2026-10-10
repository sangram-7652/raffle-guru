<?php

namespace App\Console\Commands\Backup;

use App\Services\Backup\BackupFailedException;
use App\Services\Backup\BackupOffsiteException;
use App\Services\Backup\BackupOffsiteRetentionService;
use App\Services\Backup\BackupOffsiteService;
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
 *
 * P12.6 — when BACKUP_OFFSITE_ENABLED=true, chains an offsite
 * upload+verify after the local backup succeeds, as a clearly SEPARATE
 * reported step (never folded into "Backup created" above it) — see
 * docs/BACKUPS_DISASTER_RECOVERY.md "Off-server backup (P12.6)" for why
 * a local-only success must never be reported or mistaken as an
 * off-server one. A failed offsite step makes this command exit
 * FAILURE too (so a scheduled run's non-zero exit is visible to
 * whatever monitors it) — but does NOT undo the local backup, which
 * already succeeded and remains fully usable on its own.
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
    protected $description = 'Take a logical MySQL backup, prune local backups past retention, and upload offsite if enabled.';

    public function handle(
        DatabaseBackupService $backups,
        BackupRetentionService $retention,
        BackupOffsiteService $offsite,
        BackupOffsiteRetentionService $offsiteRetention,
    ): int {
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

        if (! $offsite->enabled()) {
            return self::SUCCESS;
        }

        try {
            $uploadResult = $offsite->upload($artifact->filename);
        } catch (BackupOffsiteException $e) {
            $this->error("Offsite upload failed (local backup above still succeeded): {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info(sprintf('Offsite upload verified: %s (%d bytes).', $uploadResult['filename'], $uploadResult['size_bytes']));

        try {
            $offsitePrune = $offsiteRetention->prune();
        } catch (BackupOffsiteException $e) {
            // Same reasoning as the local-retention case above: the
            // upload just succeeded and was verified, so a cleanup
            // failure afterwards is a separate, lower-severity problem
            // that must not retroactively make this run's backup/
            // upload look like they failed too.
            $this->warn("Offsite retention cleanup failed (upload above still succeeded): {$e->getMessage()}");

            return self::SUCCESS;
        }

        if ($offsitePrune['deleted'] !== []) {
            $this->info(sprintf('Pruned %d offsite backup(s) past the retention window.', count($offsitePrune['deleted'])));
        }

        return self::SUCCESS;
    }
}
