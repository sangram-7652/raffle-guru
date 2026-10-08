<?php

namespace App\Services\Backup;

/**
 * Thrown by BackupVerificationService for anything that makes a backup
 * artifact untrustworthy: missing file, truncated/zero-byte file, a gzip
 * stream that fails its own integrity check, or a restore of its contents
 * that errors out against a throwaway database.
 */
class BackupVerificationException extends \RuntimeException {}
