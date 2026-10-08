<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Proves a backup artifact is actually restorable, not merely present —
 * see docs/BACKUPS_DISASTER_RECOVERY.md "Verification". Checks, in order:
 *
 *  1. the file exists and is at least config('backup.minimum_size_bytes')
 *  2. it's a structurally valid gzip stream (`gzip -t`)
 *  3. it actually restores into a throwaway database on the same MySQL
 *     server, created and dropped by this method, never the configured
 *     application database — this step NEVER touches production data.
 *
 * Step 3 requires a MySQL user with CREATE/DROP DATABASE privilege, which
 * the application's normal runtime user does not need and should not
 * have — see docs/BACKUPS_DISASTER_RECOVERY.md "Verification" for the
 * exact grant this expects, and the clear exception message below if
 * it's missing.
 */
final class BackupVerificationService
{
    public function __construct(
        private readonly BackupPaths $paths,
    ) {}

    /**
     * @return array{filename: string, size_bytes: int, tables_restored: int}
     */
    public function verify(?string $file = null): array
    {
        $path = $this->resolveTargetPath($file);
        $filename = basename($path);

        if (! is_file($path)) {
            throw new BackupVerificationException("Backup file does not exist: {$path}");
        }

        $sizeBytes = @filesize($path) ?: 0;
        $minimum = (int) config('backup.minimum_size_bytes');

        if ($sizeBytes < $minimum) {
            throw new BackupVerificationException(
                "Backup file is only {$sizeBytes} byte(s) (minimum {$minimum}) — missing or truncated: {$filename}",
            );
        }

        $this->assertGzipIntegrity($path, $filename);

        $tablesRestored = $this->restoreIntoThrowawayDatabaseAndCount($path, $filename);

        $result = ['filename' => $filename, 'size_bytes' => $sizeBytes, 'tables_restored' => $tablesRestored];

        Log::info('backup.verify.succeeded', $result);

        return $result;
    }

    private function resolveTargetPath(?string $file): string
    {
        if ($file === null) {
            $owned = $this->paths->listOwnedDumpFiles();

            if ($owned === []) {
                throw new BackupVerificationException(
                    'No backup files found in '.$this->paths->directory().' — nothing to verify.',
                );
            }

            return $owned[array_key_last($owned)];
        }

        $filename = basename($file);

        if (! $this->paths->isOwnedDumpFilename($filename)) {
            throw new BackupVerificationException(
                "\"{$filename}\" doesn't look like a backup this app produced — refusing to verify it.",
            );
        }

        return $this->paths->dumpPath($filename);
    }

    private function assertGzipIntegrity(string $path, string $filename): void
    {
        $result = Process::run(['gzip', '-t', $path]);

        if (! $result->successful()) {
            throw new BackupVerificationException(
                "Backup file failed gzip integrity check (corrupt/truncated): {$filename} — ".trim($result->errorOutput()),
            );
        }
    }

    private function restoreIntoThrowawayDatabaseAndCount(string $path, string $filename): int
    {
        $db = config('database.connections.mysql');
        $tempDatabase = $this->temporaryDatabaseName($filename);

        try {
            $this->createDatabase($tempDatabase);
            $this->restoreInto($db, $tempDatabase, $path, $filename);

            return (int) DB::connection('mysql')->table('information_schema.tables')
                ->where('table_schema', $tempDatabase)
                ->count();
        } finally {
            $this->dropDatabaseQuietly($tempDatabase);
        }
    }

    private function temporaryDatabaseName(string $filename): string
    {
        $prefix = (string) config('backup.verification_database_prefix');
        $suffix = substr(hash('sha256', $filename.microtime()), 0, 16);

        // Built entirely from a config value and a hex hash — always
        // matches ^[a-zA-Z0-9_]+$ — but asserted anyway since this name
        // is interpolated directly into DDL (CREATE/DROP DATABASE, which
        // MySQL does not support as a bound parameter).
        $name = $prefix.$suffix;

        if (! preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
            throw new BackupVerificationException("Refusing to use unsafe temporary database name: {$name}");
        }

        return $name;
    }

    private function createDatabase(string $database): void
    {
        try {
            DB::connection('mysql')->statement("CREATE DATABASE `{$database}`");
        } catch (\Throwable $e) {
            throw new BackupVerificationException(
                "Could not create throwaway verification database \"{$database}\" — the configured MySQL user likely lacks CREATE privilege. ".
                'See docs/BACKUPS_DISASTER_RECOVERY.md "Verification" for the grant this requires. Underlying error: '.$e->getMessage(),
            );
        }
    }

    private function dropDatabaseQuietly(string $database): void
    {
        try {
            DB::connection('mysql')->statement("DROP DATABASE IF EXISTS `{$database}`");
        } catch (\Throwable $e) {
            Log::error('backup.verify.cleanup_failed', ['database' => $database, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Decompressed SQL is never written to disk — it only ever exists as
     * in-flight chunks piped straight into the restoring client's stdin
     * (see restoreInto() below). A plaintext copy of a full database
     * dump sitting in shared temp space, even briefly, is exactly the
     * kind of thing this avoids creating in the first place.
     *
     * @return \Generator<int, string>
     */
    private function decompressedChunks(string $gzPath, string $filename): \Generator
    {
        $in = @gzopen($gzPath, 'rb');

        if ($in === false) {
            throw new BackupVerificationException("Could not open backup for decompression: {$filename}");
        }

        try {
            while (! gzeof($in)) {
                $chunk = gzread($in, 1024 * 1024);

                if ($chunk === false) {
                    throw new BackupVerificationException("Error reading compressed backup: {$filename}");
                }

                yield $chunk;
            }
        } finally {
            gzclose($in);
        }
    }

    /**
     * @param  array{host: string, port: string|int, username: string, password: string}  $db
     */
    private function restoreInto(array $db, string $tempDatabase, string $gzPath, string $filename): void
    {
        $command = [
            (string) config('backup.client_binary'),
            '-h', (string) $db['host'],
            '-P', (string) $db['port'],
            '-u', (string) $db['username'],
        ];

        if (config('backup.skip_tls')) {
            $command[] = '--skip-ssl';
        }

        $command[] = $tempDatabase;

        $result = Process::env(['MYSQL_PWD' => (string) $db['password']])
            ->timeout((int) config('backup.restore_timeout'))
            ->input($this->decompressedChunks($gzPath, $filename))
            ->run($command);

        if (! $result->successful()) {
            throw new BackupVerificationException(
                "Restoring \"{$filename}\" into the throwaway verification database failed: ".trim($result->errorOutput()),
            );
        }
    }
}
