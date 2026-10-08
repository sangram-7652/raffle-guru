<?php

namespace App\Services\Backup;

/**
 * Thrown by BackupRestoreService: an explicitly-rejected unsafe restore
 * (missing/mismatched --database, missing confirmation for a production
 * target) or a real restore failure (bad SQL, connection error).
 */
class BackupRestoreException extends \RuntimeException {}
