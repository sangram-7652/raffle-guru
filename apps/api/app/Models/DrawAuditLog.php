<?php

namespace App\Models;

use Database\Factories\DrawAuditLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable audit log of draw execution events (P9) — see
 * docs/DRAW_ENGINE_P9.md "Audit model". Deliberately a separate table
 * from competition_lifecycle_events (P8): that table logs
 * `competitions.status` transitions only (from_status/to_status), it
 * has no room for draw-specific context (ticket number, snapshot
 * checksum, failure reason) and reusing it would force draw internals
 * into a schema that doesn't fit them. This table is written only from
 * App\Services\Draw\DrawService.
 */
#[Fillable(['draw_id', 'competition_id', 'event', 'metadata'])]
class DrawAuditLog extends Model
{
    /** @use HasFactory<DrawAuditLogFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Draw, $this>
     */
    public function draw(): BelongsTo
    {
        return $this->belongsTo(Draw::class);
    }

    /**
     * @return BelongsTo<Competition, $this>
     */
    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }
}
