<?php

namespace App\Services\Backup;

/**
 * Thrown by BackupOffsiteService/BackupOffsiteRetentionService for any
 * off-server-storage failure — disabled/misconfigured offsite storage,
 * an unreachable endpoint, bad credentials, a checksum mismatch after
 * upload, or a corrupt/incomplete remote object. Always caught by the
 * command layer (see UploadOffsiteBackupCommand,
 * DownloadOffsiteBackupCommand, RunDatabaseBackupCommand) and reported
 * as a clean, non-zero-exit failure — the local backup this phase
 * builds on (P12.2) is never rolled back just because the offsite half
 * failed; see docs/BACKUPS_DISASTER_RECOVERY.md "Off-server backup
 * (P12.6)".
 */
class BackupOffsiteException extends \RuntimeException {}
