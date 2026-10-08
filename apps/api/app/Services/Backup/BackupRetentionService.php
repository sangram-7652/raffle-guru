<?php

namespace App\Services\Backup;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Deletes backup artifacts older than config('backup.retention_days') —
 * called automatically by `backup:run` after every successful backup, and
 * exposed standalone as `backup:prune` for manual/operational use.
 *
 * Safety: only ever considers files BackupPaths::isOwnedDumpFilename()
 * recognizes, inside BackupPaths::directory() — never a recursive delete,
 * never a wildcard, never a path outside that one directory.
 */
final class BackupRetentionService
{
    public function __construct(
        private readonly BackupPaths $paths,
    ) {}

    /**
     * @return list<array{filename: string, size_bytes: int}> the files
     *                                                        actually deleted
     */
    public function prune(?CarbonImmutable $now = null): array
    {
        $now = $now ?? CarbonImmutable::now();
        $cutoff = $now->subDays((int) config('backup.retention_days'));
        $deleted = [];

        foreach ($this->paths->listOwnedDumpFiles() as $dumpPath) {
            $mtime = @filemtime($dumpPath);

            if ($mtime === false) {
                continue;
            }

            if (CarbonImmutable::createFromTimestamp($mtime)->greaterThan($cutoff)) {
                continue;
            }

            $sizeBytes = @filesize($dumpPath) ?: 0;
            $filename = basename($dumpPath);
            $metaPath = $this->paths->metaPath($filename);

            // Best-effort: the dump is the artifact that actually matters:
            // report the dump's own deletion result and never let a
            // missing/already-gone sidecar fail the whole prune run.
            @unlink($dumpPath);
            @unlink($metaPath);

            $deleted[] = ['filename' => $filename, 'size_bytes' => $sizeBytes];
        }

        Log::info('backup.prune.completed', [
            'deleted_count' => count($deleted),
            'deleted_bytes' => array_sum(array_column($deleted, 'size_bytes')),
            'retention_days' => (int) config('backup.retention_days'),
        ]);

        return $deleted;
    }
}
