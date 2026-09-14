<?php

namespace App\Models;

use Database\Factories\CompetitionLifecycleEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable audit log of competition lifecycle transitions (P8) — see
 * docs/COMPETITION_LIFECYCLE_P8.md. Written only from
 * CompetitionLifecycleService::transition(), one row per transition
 * that actually happened.
 */
#[Fillable(['competition_id', 'from_status', 'to_status', 'reason'])]
class CompetitionLifecycleEvent extends Model
{
    /** @use HasFactory<CompetitionLifecycleEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<Competition, $this>
     */
    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }
}
