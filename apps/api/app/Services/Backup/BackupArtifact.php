<?php

namespace App\Services\Backup;

use Carbon\CarbonImmutable;

/**
 * Metadata for one completed backup — written to disk as the dump's
 * ".meta.json" sidecar and also what DatabaseBackupService logs on
 * success. Deliberately carries no secrets (no DSN, no password).
 */
final readonly class BackupArtifact
{
    public function __construct(
        public string $filename,
        public string $path,
        public string $database,
        public string $environment,
        public CarbonImmutable $createdAt,
        public int $sizeBytes,
        public string $sha256,
        public int $durationMs,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toMetadata(): array
    {
        return [
            'filename' => $this->filename,
            'database' => $this->database,
            'environment' => $this->environment,
            'timestamp' => $this->createdAt->toIso8601String(),
            'format' => 'sql.gz',
            'format_version' => 1,
            'size_bytes' => $this->sizeBytes,
            'sha256' => $this->sha256,
            'duration_ms' => $this->durationMs,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toLogContext(): array
    {
        return [
            'filename' => $this->filename,
            'database' => $this->database,
            'environment' => $this->environment,
            'size_bytes' => $this->sizeBytes,
            'duration_ms' => $this->durationMs,
        ];
    }
}
