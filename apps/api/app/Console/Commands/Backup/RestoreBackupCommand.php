<?php

namespace App\Console\Commands\Backup;

use App\Services\Backup\BackupRestoreException;
use App\Services\Backup\BackupRestoreService;
use Illuminate\Console\Command;

/**
 * `php artisan backup:restore {file} --database=<target> [--force]
 * [--confirm-production-overwrite]` — manual-only, operator-driven
 * restore. See docs/BACKUPS_DISASTER_RECOVERY.md "Restore procedure" for
 * the full workflow this is step 2-4 of (select/validate/create/restore
 * — migrations, cache clearing, service restarts, and health checks are
 * documented there as separate, deliberately manual steps).
 *
 * Safety:
 *  - --database is mandatory and has no default — there is no way to
 *    invoke this command that implicitly targets "whatever the app is
 *    currently configured to use".
 *  - if --database names the live configured database, an additional
 *    --confirm-production-overwrite flag is required, or the command
 *    aborts without touching anything.
 *  - without --force, an interactive confirmation describes exactly
 *    what will happen before anything runs.
 *
 * This command can NEVER be invoked in a way that silently overwrites
 * production — every dangerous path requires an explicit, named flag.
 */
class RestoreBackupCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'backup:restore
        {file : Backup filename to restore (as listed in BACKUP_PATH)}
        {--database= : Target database to restore into (required, no default)}
        {--force : Skip the interactive confirmation prompt}
        {--confirm-production-overwrite : Required in addition to --database when the target is the live configured database}';

    /**
     * @var string
     */
    protected $description = 'Restore a backup into an explicitly-named database. Requires --database; never targets production without --confirm-production-overwrite.';

    public function handle(BackupRestoreService $restore): int
    {
        $database = $this->option('database');

        if (! is_string($database) || $database === '') {
            $this->error('backup:restore requires --database=<target>. There is no default target — this is deliberate, see docs/BACKUPS_DISASTER_RECOVERY.md.');

            return self::FAILURE;
        }

        $liveDatabase = (string) config('database.connections.mysql.database');

        if ($database === $liveDatabase && ! $this->option('confirm-production-overwrite')) {
            $this->error(sprintf(
                '"%s" is the live configured database. Restoring here requires --confirm-production-overwrite in addition to --database — refusing.',
                $database,
            ));

            return self::FAILURE;
        }

        try {
            $dumpPath = $restore->resolveDumpPath($this->argument('file'));
        } catch (BackupRestoreException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $this->option('force')) {
            $confirmed = $this->confirm(sprintf(
                'Restore "%s" into database "%s"? Existing tables with the same name will be dropped and replaced per the dump\'s own statements.',
                basename($dumpPath),
                $database,
            ));

            if (! $confirmed) {
                $this->warn('Aborted — nothing was changed.');

                return self::FAILURE;
            }
        }

        try {
            $restore->restoreInto($dumpPath, $database);
        } catch (BackupRestoreException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Restored \"{$database}\" from ".basename($dumpPath).'.');
        $this->warn('Remember the remaining manual steps: migrations (only if needed), cache clear, service restart, health checks — see docs/BACKUPS_DISASTER_RECOVERY.md "Restore procedure".');

        return self::SUCCESS;
    }
}
