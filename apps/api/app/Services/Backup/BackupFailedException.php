<?php

namespace App\Services\Backup;

/**
 * Thrown by DatabaseBackupService when mariadb-dump fails, or "succeeds"
 * but produces a truncated/empty artifact — never caught silently; the
 * command that triggers a backup lets this propagate so it reaches the
 * normal exception-report pipeline (and therefore Sentry, see P12.1) and
 * the process exits non-zero.
 */
class BackupFailedException extends \RuntimeException {}
