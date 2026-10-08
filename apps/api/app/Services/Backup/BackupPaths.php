<?php

namespace App\Services\Backup;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Single source of truth for where backups live and what their filenames
 * look like — used by DatabaseBackupService (writes), BackupRetentionService
 * (lists/deletes), BackupVerificationService and BackupRestoreService
 * (reads), and their tests. Centralizing this is what lets
 * BackupRetentionService prove it only ever touches files matching this
 * exact shape, inside this exact directory.
 */
final class BackupPaths
{
    public function directory(): string
    {
        return rtrim((string) config('backup.path'), '/');
    }

    public function ensureDirectoryExists(): void
    {
        $dir = $this->directory();

        if (! is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
    }

    public function filenameFor(string $database, CarbonImmutable $at): string
    {
        $timestamp = $at->format('Ymd_His');
        $prefix = (string) config('backup.prefix');

        return "{$prefix}_{$database}_{$timestamp}".config('backup.dump_extension');
    }

    public function dumpPath(string $filename): string
    {
        return $this->directory().'/'.$filename;
    }

    public function metaPath(string $dumpFilename): string
    {
        return $this->directory().'/'.$this->withMetaExtension($dumpFilename);
    }

    public function withMetaExtension(string $dumpFilename): string
    {
        $dumpExt = (string) config('backup.dump_extension');
        $metaExt = (string) config('backup.meta_extension');

        return Str::endsWith($dumpFilename, $dumpExt)
            ? Str::replaceLast($dumpExt, $metaExt, $dumpFilename)
            : $dumpFilename.$metaExt;
    }

    /**
     * True only for a filename this app's own backup tooling could have
     * produced — "{prefix}_{anything}_{14 digits}{dump_extension}". Both
     * backup:prune and backup:verify's "pick the latest" fallback rely on
     * this never matching an unrelated file an operator happened to also
     * put in BACKUP_PATH.
     */
    public function isOwnedDumpFilename(string $filename): bool
    {
        $prefix = preg_quote((string) config('backup.prefix'), '/');
        $ext = preg_quote((string) config('backup.dump_extension'), '/');

        return (bool) preg_match("/^{$prefix}_.+_\\d{8}_\\d{6}{$ext}$/", $filename);
    }

    /**
     * @return list<string> absolute paths of owned dump files in the
     *                      backup directory, lexicographically sorted
     *                      (which, given the Ymd_His timestamp, is also
     *                      chronological — newest last).
     */
    public function listOwnedDumpFiles(): array
    {
        $dir = $this->directory();

        if (! is_dir($dir)) {
            return [];
        }

        $files = array_filter(
            scandir($dir) ?: [],
            fn (string $name): bool => $this->isOwnedDumpFilename($name),
        );

        sort($files);

        return array_values(array_map(fn (string $name): string => "{$dir}/{$name}", $files));
    }
}
