<?php

namespace App\Services\Backup;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Deletes off-server backup objects older than
 * config('backup.offsite.retention_days') — mirrors
 * BackupRetentionService's local pruning, with one additional,
 * unconditional guard local pruning doesn't need: the single most
 * recent remote backup is NEVER deleted by this service, regardless of
 * its age. Local retention can safely delete everything past the
 * window because a fresh local backup is always about to be taken
 * again tomorrow on the SAME server; the remote copy is the one that
 * survives losing that server, so if uploads have been failing silently
 * for longer than the retention window, this must not be the thing that
 * wipes out the last good copy anyway. See
 * docs/BACKUPS_DISASTER_RECOVERY.md "Off-server backup (P12.6)".
 *
 * Ages a backup by the timestamp embedded in its OWN filename (see
 * BackupPaths::timestampFromFilename()), not by when it happened to be
 * uploaded/re-uploaded to this provider — see that method's docblock.
 */
final class BackupOffsiteRetentionService
{
    public function __construct(
        private readonly BackupOffsiteService $offsite,
    ) {}

    /**
     * `newest_past_retention` true is a real operator signal — it means
     * the single most recent remote backup is already older than the
     * retention window, which can only happen if offsite uploads have
     * been failing (or never configured until now) for longer than
     * that window. Surfaced by PruneOffsiteBackupsCommand as a warning,
     * not hidden inside a "nothing to prune" message.
     *
     * @return array{deleted: list<array{filename: string, size_bytes: int}>, newest_filename: string|null, newest_past_retention: bool}
     */
    public function prune(?CarbonImmutable $now = null): array
    {
        $now = $now ?? CarbonImmutable::now();
        $cutoff = $now->subDays((int) config('backup.offsite.retention_days'));

        $owned = $this->offsite->listOwnedRemoteDumps();

        if ($owned === []) {
            Log::info('backup.offsite_prune.completed', ['deleted_count' => 0, 'deleted_bytes' => 0, 'retention_days' => (int) config('backup.offsite.retention_days')]);

            return ['deleted' => [], 'newest_filename' => null, 'newest_past_retention' => false];
        }

        // listOwnedRemoteDumps() returns oldest-first — the last
        // element is always the single most recent remote backup, the
        // one this method must never delete no matter how old it is.
        $newest = $owned[array_key_last($owned)];
        $newestFilename = $newest['filename'];
        $newestPastRetention = $newest['last_modified']->lessThanOrEqualTo($cutoff);

        $deleted = [];

        foreach ($owned as $entry) {
            if ($entry['filename'] === $newestFilename) {
                continue;
            }

            if ($entry['last_modified']->greaterThan($cutoff)) {
                continue;
            }

            $this->offsite->deleteRemote($entry['filename']);

            $deleted[] = ['filename' => $entry['filename'], 'size_bytes' => $entry['size_bytes']];
        }

        Log::info('backup.offsite_prune.completed', [
            'deleted_count' => count($deleted),
            'deleted_bytes' => array_sum(array_column($deleted, 'size_bytes')),
            'retention_days' => (int) config('backup.offsite.retention_days'),
            'protected_newest' => $newestFilename,
            'newest_past_retention' => $newestPastRetention,
        ]);

        if ($newestPastRetention) {
            Log::warning('backup.offsite_prune.newest_past_retention', ['filename' => $newestFilename]);
        }

        return ['deleted' => $deleted, 'newest_filename' => $newestFilename, 'newest_past_retention' => $newestPastRetention];
    }
}
