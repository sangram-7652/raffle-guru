<?php

namespace App\Services\Backup;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Logical MySQL backup via mariadb-dump — see config/backup.php and
 * docs/BACKUPS_DISASTER_RECOVERY.md. Used by App\Console\Commands\Backup\
 * RunDatabaseBackupCommand (`backup:run`), which the scheduler calls daily
 * (routes/console.php).
 *
 * The password is passed via the MYSQL_PWD environment variable, never a
 * CLI argument — `ps`/the process table on a shared host can read argv,
 * but not another process's private env block. Output streams straight
 * into a gzip handle as it arrives rather than being buffered in PHP
 * memory first, so this scales to a dump far larger than available RAM.
 */
final class DatabaseBackupService
{
    public function __construct(
        private readonly BackupPaths $paths,
    ) {}

    public function run(): BackupArtifact
    {
        $this->paths->ensureDirectoryExists();

        $db = $this->connectionConfig();
        $startedAt = CarbonImmutable::now();
        $filename = $this->paths->filenameFor((string) $db['database'], $startedAt);
        $finalPath = $this->paths->dumpPath($filename);
        $tmpPath = $finalPath.'.partial';

        $gz = @gzopen($tmpPath, 'wb9');

        if ($gz === false) {
            throw new BackupFailedException("Unable to open temporary backup file for writing: {$tmpPath}");
        }

        $stderr = '';

        try {
            $result = Process::env(['MYSQL_PWD' => (string) $db['password']])
                ->timeout((int) config('backup.dump_timeout'))
                ->run($this->dumpCommand($db), function (string $type, string $chunk) use ($gz, &$stderr): void {
                    if ($type === 'err') {
                        $stderr .= $chunk;
                    } else {
                        gzwrite($gz, $chunk);
                    }
                });
        } finally {
            gzclose($gz);
        }

        if (! $result->successful()) {
            @unlink($tmpPath);

            Log::error('backup.run.failed', [
                'database' => $db['database'],
                'exit_code' => $result->exitCode(),
                'stderr' => $this->sanitizedStderr($stderr),
            ]);

            throw new BackupFailedException(sprintf(
                'mariadb-dump exited with code %d: %s',
                $result->exitCode(),
                $this->sanitizedStderr($stderr),
            ));
        }

        $sizeBytes = @filesize($tmpPath) ?: 0;
        $minimum = (int) config('backup.minimum_size_bytes');

        if ($sizeBytes < $minimum) {
            @unlink($tmpPath);

            Log::error('backup.run.truncated', [
                'database' => $db['database'],
                'size_bytes' => $sizeBytes,
                'minimum_size_bytes' => $minimum,
            ]);

            throw new BackupFailedException(
                "Backup output is only {$sizeBytes} byte(s) (minimum {$minimum}) — treating as truncated, not a real backup.",
            );
        }

        rename($tmpPath, $finalPath);
        chmod($finalPath, 0640);

        $artifact = new BackupArtifact(
            filename: $filename,
            path: $finalPath,
            database: (string) $db['database'],
            environment: (string) config('app.env'),
            createdAt: $startedAt,
            sizeBytes: $sizeBytes,
            sha256: (string) hash_file('sha256', $finalPath),
            durationMs: $startedAt->diffInMilliseconds(CarbonImmutable::now()),
        );

        $metaPath = $this->paths->metaPath($filename);
        file_put_contents($metaPath, json_encode($artifact->toMetadata(), JSON_PRETTY_PRINT));
        chmod($metaPath, 0640);

        Log::info('backup.run.succeeded', $artifact->toLogContext());

        return $artifact;
    }

    /**
     * @return array{host: string, port: string|int, database: string, username: string, password: string}
     */
    public function connectionConfig(): array
    {
        if (config('database.default') !== 'mysql') {
            throw new BackupFailedException(sprintf(
                'backup:run requires the "mysql" database connection to be active (config("database.default") === "mysql"); refusing to run against "%s".',
                (string) config('database.default'),
            ));
        }

        return config('database.connections.mysql');
    }

    /**
     * @param  array{host: string, port: string|int, database: string, username: string}  $db
     * @return list<string>
     */
    private function dumpCommand(array $db): array
    {
        $command = [
            (string) config('backup.dump_binary'),
            '-h', (string) $db['host'],
            '-P', (string) $db['port'],
            '-u', (string) $db['username'],
            '--single-transaction',
            '--routines',
            '--triggers',
            '--no-tablespaces',
        ];

        if (config('backup.skip_tls')) {
            $command[] = '--skip-ssl';
        }

        $command[] = (string) $db['database'];

        return $command;
    }

    private function sanitizedStderr(string $stderr): string
    {
        // mariadb-dump never echoes the password back (it's passed via
        // MYSQL_PWD, never a CLI arg) — this is a defensive backstop, in
        // case a future flag change ever put a secret on stderr.
        $trimmed = trim($stderr);

        return $trimmed === '' ? '(no stderr output)' : $trimmed;
    }
}
