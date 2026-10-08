<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Restores a backup artifact into an operator-specified MySQL database —
 * used only by App\Console\Commands\Backup\RestoreBackupCommand
 * (`backup:restore`), never automatically. See
 * docs/BACKUPS_DISASTER_RECOVERY.md "Restore procedure".
 *
 * This class performs no safety confirmation itself — the command is
 * responsible for requiring an explicit --database and, when that target
 * is the live configured database, an explicit extra confirmation flag.
 * By the time restoreInto() runs, the caller has already decided the
 * target is intentional.
 */
final class BackupRestoreService
{
    public function __construct(
        private readonly BackupPaths $paths,
    ) {}

    public function resolveDumpPath(string $file): string
    {
        $filename = basename($file);

        if (! $this->paths->isOwnedDumpFilename($filename)) {
            throw new BackupRestoreException(
                "\"{$filename}\" doesn't look like a backup this app produced — refusing to restore it.",
            );
        }

        $path = $this->paths->dumpPath($filename);

        if (! is_file($path)) {
            throw new BackupRestoreException("Backup file does not exist: {$path}");
        }

        return $path;
    }

    /**
     * Creates the target database if it doesn't already exist, then
     * restores the given dump into it. Never drops an existing database
     * first — a restore onto an already-populated database relies on the
     * dump's own `DROP TABLE IF EXISTS` statements (standard mariadb-dump
     * output) rather than this method widening its blast radius to the
     * whole schema.
     */
    public function restoreInto(string $dumpPath, string $database): void
    {
        if (! preg_match('/^[a-zA-Z0-9_]+$/', $database)) {
            throw new BackupRestoreException("Refusing to restore into database with unsafe name: {$database}");
        }

        $db = config('database.connections.mysql');

        DB::connection('mysql')->statement("CREATE DATABASE IF NOT EXISTS `{$database}`");

        $command = [
            (string) config('backup.client_binary'),
            '-h', (string) $db['host'],
            '-P', (string) $db['port'],
            '-u', (string) $db['username'],
        ];

        if (config('backup.skip_tls')) {
            $command[] = (string) config('backup.tls_disable_flag');
        }

        $command[] = $database;

        $result = Process::env(['MYSQL_PWD' => (string) $db['password']])
            ->timeout((int) config('backup.restore_timeout'))
            ->input($this->decompressedChunks($dumpPath))
            ->run($command);

        if (! $result->successful()) {
            Log::error('backup.restore.failed', [
                'database' => $database,
                'exit_code' => $result->exitCode(),
            ]);

            throw new BackupRestoreException(
                "Restoring into \"{$database}\" failed: ".trim($result->errorOutput()),
            );
        }

        Log::info('backup.restore.succeeded', ['database' => $database, 'dump' => basename($dumpPath)]);
    }

    /**
     * Decompressed SQL is never written to disk — see the identical
     * reasoning on BackupVerificationService::decompressedChunks().
     *
     * @return \Generator<int, string>
     */
    private function decompressedChunks(string $gzPath): \Generator
    {
        $in = @gzopen($gzPath, 'rb');

        if ($in === false) {
            throw new BackupRestoreException("Could not open backup for decompression: {$gzPath}");
        }

        try {
            while (! gzeof($in)) {
                $chunk = gzread($in, 1024 * 1024);

                if ($chunk === false) {
                    throw new BackupRestoreException("Error reading compressed backup: {$gzPath}");
                }

                yield $chunk;
            }
        } finally {
            gzclose($in);
        }
    }
}
