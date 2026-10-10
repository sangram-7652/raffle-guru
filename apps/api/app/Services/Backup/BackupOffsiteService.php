<?php

namespace App\Services\Backup;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Uploads a completed local backup (see DatabaseBackupService, P12.2)
 * to off-server object storage, and proves the upload is actually
 * intact — never just that the HTTP call returned success. See
 * docs/BACKUPS_DISASTER_RECOVERY.md "Off-server backup (P12.6)".
 *
 * Storage-provider-agnostic by construction: this talks only to
 * Laravel's Filesystem contract against whichever disk
 * config('backup.offsite.disk') names (config/filesystems.php's
 * 'backups' disk, any S3-compatible endpoint — AWS S3, DigitalOcean
 * Spaces, Cloudflare R2, etc. — adobe/s3mock for local/CI testing,
 * see tests/Feature/Backup/Concerns/ConfiguresOffsiteTestStorage.php)
 * — nothing here is AWS-specific.
 *
 * A successful local backup is never mistaken for a successful
 * off-server one: this class is never called implicitly by anything
 * that only did a local backup, and every method here either returns a
 * result describing exactly what was verified or throws
 * BackupOffsiteException — there is no silent partial-success path.
 */
final class BackupOffsiteService
{
    public function __construct(
        private readonly BackupPaths $paths,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('backup.offsite.enabled');
    }

    private function assertEnabled(): void
    {
        if (! $this->enabled()) {
            throw new BackupOffsiteException(
                'Off-server backup is not enabled. Set BACKUP_OFFSITE_ENABLED=true and the BACKUP_OFFSITE_* '.
                'variables — see docs/BACKUPS_DISASTER_RECOVERY.md "Off-server backup (P12.6)".',
            );
        }
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('backup.offsite.disk'));
    }

    /**
     * Local filename -> remote object key. Flat (no subfolder): the
     * offsite disk is a DEDICATED bucket/credential for backups only
     * (see config/filesystems.php's 'backups' disk comment), so there's
     * no sibling content in this bucket a prefix would need to separate
     * backups from.
     */
    private function remoteKey(string $filename): string
    {
        return $filename;
    }

    public function remoteExists(string $filename): bool
    {
        try {
            return $this->disk()->exists($this->remoteKey($filename));
        } catch (\Throwable $e) {
            throw $this->wrapStorageException($e, 'check whether a backup already exists remotely');
        }
    }

    /**
     * Uploads the dump + its .meta.json sidecar, then proves the
     * uploaded dump is byte-for-byte what was meant to be uploaded by
     * downloading it straight back and comparing a freshly-computed
     * SHA-256 — not a provider-reported ETag (which, for a multipart
     * upload, is not a simple hash of the object's contents at all) and
     * not trusting the PUT response's HTTP 200 alone. This is the exact
     * same "prove it, don't assume it" posture backup:verify already
     * takes with a real restore rather than trusting metadata — see
     * docs/BACKUPS_DISASTER_RECOVERY.md "Verification".
     *
     * Idempotent: re-running this for a filename already present
     * remotely with a matching size skips re-uploading the bytes (no
     * reason to pay upload bandwidth twice for the same content) but
     * still re-downloads and re-verifies the hash every time — "already
     * uploaded" is never treated as "therefore still intact" without
     * re-checking.
     *
     * The whole upload-verify sequence (not just a single HTTP request
     * — the AWS SDK itself already retries a single request via
     * config/filesystems.php's 'retries' option) is retried up to
     * config('backup.offsite.upload_attempts') times with a delay
     * between attempts, so one transient network blip doesn't surface
     * as a hard failure for an operator to act on.
     *
     * @return array{filename: string, size_bytes: int, sha256: string, already_present: bool}
     */
    public function upload(string $dumpFilename): array
    {
        $this->assertEnabled();

        if (! $this->paths->isOwnedDumpFilename($dumpFilename)) {
            throw new BackupOffsiteException(
                "\"{$dumpFilename}\" doesn't look like a backup this app produced — refusing to upload it.",
            );
        }

        $dumpPath = $this->paths->dumpPath($dumpFilename);

        if (! is_file($dumpPath)) {
            throw new BackupOffsiteException("Local backup file does not exist: {$dumpPath}");
        }

        $expectedSha256 = (string) hash_file('sha256', $dumpPath);
        $sizeBytes = (int) (@filesize($dumpPath) ?: 0);

        $attempts = max(1, (int) config('backup.offsite.upload_attempts'));
        $delayMs = (int) config('backup.offsite.retry_delay_ms');

        // The existence check runs INSIDE the retried closure, not
        // once beforehand — a transient failure reaching storage to
        // even check "is it already there" must be retried the same
        // as any other step here, and re-checking reality fresh on
        // every attempt (not trusting a stale answer from a previous,
        // failed attempt) is correct regardless: a previous attempt
        // may have partially succeeded before failing elsewhere.
        $alreadyPresent = false;

        retry($attempts, function (int $attempt) use ($dumpFilename, $dumpPath, $expectedSha256, &$alreadyPresent): void {
            $alreadyPresent = $this->remoteExists($dumpFilename);

            if (! $alreadyPresent || ! $this->remoteSizeMatches($dumpFilename, $dumpPath)) {
                $this->putDump($dumpFilename, $dumpPath);
                $this->putMetaIfPresent($dumpFilename);
            }

            $this->verifyRemoteIntegrity($dumpFilename, $expectedSha256, $attempt);
        }, $delayMs, function (\Throwable $e) use ($dumpFilename): bool {
            Log::warning('backup.offsite_upload.attempt_failed', [
                'filename' => $dumpFilename,
                'error' => $this->sanitizedMessage($e),
            ]);

            // Any failure here is worth retrying — a bad upload, a
            // dropped connection during download-verify, a transient
            // 5xx — there is no failure mode in this sequence that a
            // retry can make *worse*, since every attempt starts by
            // re-checking (and re-uploading if needed) the object
            // before re-verifying it.
            return true;
        });

        $result = [
            'filename' => $dumpFilename,
            'size_bytes' => $sizeBytes,
            'sha256' => $expectedSha256,
            'already_present' => $alreadyPresent,
        ];

        Log::info('backup.offsite_upload.succeeded', $result);

        return $result;
    }

    private function remoteSizeMatches(string $filename, string $localPath): bool
    {
        try {
            $remoteSize = $this->disk()->size($this->remoteKey($filename));
        } catch (\Throwable) {
            return false;
        }

        return $remoteSize === (@filesize($localPath) ?: -1);
    }

    private function putDump(string $filename, string $localPath): void
    {
        $stream = @fopen($localPath, 'rb');

        if ($stream === false) {
            throw new BackupOffsiteException("Could not open local backup for upload: {$filename}");
        }

        try {
            $this->disk()->writeStream($this->remoteKey($filename), $stream);
        } catch (\Throwable $e) {
            throw $this->wrapStorageException($e, "upload backup \"{$filename}\"");
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function putMetaIfPresent(string $dumpFilename): void
    {
        $metaPath = $this->paths->metaPath($dumpFilename);

        if (! is_file($metaPath)) {
            return;
        }

        $metaFilename = $this->paths->withMetaExtension($dumpFilename);
        $contents = (string) file_get_contents($metaPath);

        try {
            $this->disk()->put($this->remoteKey($metaFilename), $contents);
        } catch (\Throwable $e) {
            throw $this->wrapStorageException($e, "upload backup metadata \"{$metaFilename}\"");
        }
    }

    /**
     * Downloads the just-uploaded object straight back and hashes it
     * while streaming — never buffering the whole object in memory —
     * comparing against the hash of the LOCAL file computed immediately
     * before upload. A mismatch deletes the bad remote object before
     * throwing, so a subsequent attempt's existence/size check (above)
     * can't mistake the corrupt object for "already present, nothing to
     * do".
     */
    private function verifyRemoteIntegrity(string $filename, string $expectedSha256, int $attempt): void
    {
        $key = $this->remoteKey($filename);

        try {
            $stream = $this->disk()->readStream($key);
        } catch (\Throwable $e) {
            throw $this->wrapStorageException($e, "download backup \"{$filename}\" back for verification");
        }

        if ($stream === null || $stream === false) {
            throw new BackupOffsiteException("Could not open remote backup for verification: {$filename}");
        }

        $context = hash_init('sha256');

        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 1024 * 1024);

                if ($chunk === false) {
                    throw new BackupOffsiteException("Error reading remote backup during verification: {$filename}");
                }

                hash_update($context, $chunk);
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $actualSha256 = hash_final($context);

        if (! hash_equals($expectedSha256, $actualSha256)) {
            Log::error('backup.offsite_upload.checksum_mismatch', [
                'filename' => $filename,
                'attempt' => $attempt,
                'expected_sha256' => $expectedSha256,
                'actual_sha256' => $actualSha256,
            ]);

            $this->deleteQuietly($key);

            throw new BackupOffsiteException(
                "Uploaded backup \"{$filename}\" failed remote integrity verification (SHA-256 mismatch) on attempt {$attempt} — the remote copy was deleted so it's never mistaken for a good backup.",
            );
        }
    }

    /**
     * Sorted oldest first (by the filename's OWN embedded timestamp —
     * see BackupPaths::timestampFromFilename() for why that, not the
     * provider's reported upload time, is what retention should age
     * backups by).
     *
     * @return list<array{filename: string, size_bytes: int, last_modified: CarbonImmutable}>
     */
    public function listOwnedRemoteDumps(): array
    {
        $this->assertEnabled();

        try {
            $allFiles = $this->disk()->files();
        } catch (\Throwable $e) {
            throw $this->wrapStorageException($e, 'list remote backups');
        }

        $owned = [];

        foreach ($allFiles as $path) {
            $filename = basename($path);

            if (! $this->paths->isOwnedDumpFilename($filename)) {
                continue;
            }

            $timestamp = $this->paths->timestampFromFilename($filename);

            if ($timestamp === null) {
                continue;
            }

            $owned[] = [
                'filename' => $filename,
                'size_bytes' => (int) $this->disk()->size($path),
                'last_modified' => $timestamp,
            ];
        }

        // CarbonImmutable has no compare() method — PHP's DateTimeInterface
        // objects support <=> natively, which is exactly a chronological
        // comparison here.
        usort($owned, fn (array $a, array $b): int => $a['last_modified'] <=> $b['last_modified']);

        return $owned;
    }

    public function deleteRemote(string $filename): void
    {
        $key = $this->remoteKey($filename);
        $metaKey = $this->remoteKey($this->paths->withMetaExtension($filename));

        try {
            $this->disk()->delete($key);
        } catch (\Throwable $e) {
            throw $this->wrapStorageException($e, "delete remote backup \"{$filename}\"");
        }

        // Best-effort: the dump is the artifact that matters; a
        // missing/already-gone sidecar never fails the deletion of the
        // dump itself — same reasoning as BackupRetentionService's
        // local prune().
        $this->deleteQuietly($metaKey);
    }

    /**
     * Downloads a remote backup into BACKUP_PATH (never an arbitrary
     * caller-supplied location) via the same temp-then-atomic-rename
     * pattern DatabaseBackupService uses for a freshly-taken local
     * backup — a process crash mid-download leaves only a ".partial"
     * file behind, never something that could be mistaken for a
     * complete backup under its real name. Verifies the download
     * against the remote .meta.json sidecar's recorded SHA-256 (not a
     * freshly-computed "local" hash, since there is nothing else
     * independent to compare a download against) — see
     * docs/BACKUPS_DISASTER_RECOVERY.md "Disaster recovery procedure".
     *
     * @return string the local path the backup was downloaded to —
     *                always BackupPaths::dumpPath($filename), so
     *                backup:verify/backup:restore can use it
     *                completely unmodified.
     */
    public function download(string $filename): string
    {
        $this->assertEnabled();

        if (! $this->paths->isOwnedDumpFilename($filename)) {
            throw new BackupOffsiteException(
                "\"{$filename}\" doesn't look like a backup this app produced — refusing to download it.",
            );
        }

        $expectedSha256 = $this->fetchExpectedSha256FromRemoteMeta($filename);

        $this->paths->ensureDirectoryExists();
        $finalPath = $this->paths->dumpPath($filename);
        $tmpPath = $finalPath.'.partial';

        try {
            $stream = $this->disk()->readStream($this->remoteKey($filename));
        } catch (\Throwable $e) {
            throw $this->wrapStorageException($e, "download backup \"{$filename}\"");
        }

        if ($stream === null || $stream === false) {
            throw new BackupOffsiteException("Remote backup does not exist or could not be opened: {$filename}");
        }

        $out = @fopen($tmpPath, 'wb');

        if ($out === false) {
            if (is_resource($stream)) {
                fclose($stream);
            }

            throw new BackupOffsiteException("Unable to open temporary file for download: {$tmpPath}");
        }

        $context = hash_init('sha256');

        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 1024 * 1024);

                if ($chunk === false) {
                    throw new BackupOffsiteException("Error reading remote backup during download: {$filename}");
                }

                hash_update($context, $chunk);
                fwrite($out, $chunk);
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            fclose($out);
        }

        $actualSha256 = hash_final($context);

        if (! hash_equals($expectedSha256, $actualSha256)) {
            @unlink($tmpPath);

            Log::error('backup.offsite_download.checksum_mismatch', [
                'filename' => $filename,
                'expected_sha256' => $expectedSha256,
                'actual_sha256' => $actualSha256,
            ]);

            throw new BackupOffsiteException(
                "Downloaded backup \"{$filename}\" failed integrity verification (SHA-256 mismatch) — the partial download was deleted, nothing was left under the real filename.",
            );
        }

        rename($tmpPath, $finalPath);
        chmod($finalPath, 0640);

        Log::info('backup.offsite_download.succeeded', ['filename' => $filename, 'sha256' => $actualSha256]);

        return $finalPath;
    }

    private function fetchExpectedSha256FromRemoteMeta(string $dumpFilename): string
    {
        $metaKey = $this->remoteKey($this->paths->withMetaExtension($dumpFilename));

        try {
            $exists = $this->disk()->exists($metaKey);
        } catch (\Throwable $e) {
            throw $this->wrapStorageException($e, "read remote backup metadata for \"{$dumpFilename}\"");
        }

        if (! $exists) {
            throw new BackupOffsiteException(
                "No remote .meta.json found for \"{$dumpFilename}\" — refusing to download without something independent to verify its integrity against.",
            );
        }

        $contents = $this->disk()->get($metaKey);
        $meta = json_decode((string) $contents, true);
        $sha256 = is_array($meta) ? ($meta['sha256'] ?? null) : null;

        if (! is_string($sha256) || $sha256 === '') {
            throw new BackupOffsiteException("Remote metadata for \"{$dumpFilename}\" has no usable sha256 field.");
        }

        return $sha256;
    }

    private function deleteQuietly(string $key): void
    {
        try {
            $this->disk()->delete($key);
        } catch (\Throwable $e) {
            Log::error('backup.offsite.cleanup_failed', ['key' => $key, 'error' => $this->sanitizedMessage($e)]);
        }
    }

    /**
     * Wraps a storage-layer exception without ever passing its message
     * through unexamined — an AWS SDK exception can embed the request
     * that failed, which is never allowed to include the secret key
     * (it isn't — AWS SigV4 never puts the secret itself in a request,
     * only a derived signature — but this stays defensive rather than
     * relying on that fact never changing) and is otherwise safe to
     * surface verbatim for operator debugging.
     */
    private function wrapStorageException(\Throwable $e, string $action): BackupOffsiteException
    {
        return new BackupOffsiteException(
            "Failed to {$action}: ".$this->sanitizedMessage($e),
            previous: $e,
        );
    }

    private function sanitizedMessage(\Throwable $e): string
    {
        $message = $e->getMessage();
        $secret = (string) config('backup.offsite.disk') !== '' ? (string) config('filesystems.disks.'.config('backup.offsite.disk').'.secret') : '';

        if ($secret !== '' && str_contains($message, $secret)) {
            $message = str_replace($secret, '[redacted]', $message);
        }

        return $message;
    }
}
