<?php

namespace App\Models;

use App\Enums\DrawStatus;
use App\Enums\DrawType;
use Database\Factories\DrawFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One row per draw ATTEMPT for a competition (P9) — see
 * docs/DRAW_ENGINE_P9.md. Written only from App\Services\Draw\DrawService.
 * Never edited except by that service's own status/timestamp fields as
 * an attempt progresses (running -> completed|failed); the winning
 * ticket itself lives on the separate, immutable DrawResult.
 */
#[Fillable([
    'competition_id',
    'type',
    'status',
    'attempt',
    'snapshot_at',
    'eligible_ticket_count',
    'eligibility_version',
    'snapshot_checksum',
    'randomness_method',
    'started_at',
    'completed_at',
    'failed_at',
    'failure_reason',
])]
class Draw extends Model
{
    /** @use HasFactory<DrawFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DrawType::class,
            'status' => DrawStatus::class,
            'snapshot_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Competition, $this>
     */
    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    /**
     * @return HasOne<DrawResult, $this>
     */
    public function result(): HasOne
    {
        return $this->hasOne(DrawResult::class);
    }

    /**
     * @return HasMany<DrawAuditLog, $this>
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(DrawAuditLog::class);
    }
}
