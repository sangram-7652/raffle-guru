<?php

namespace App\Models;

use Database\Factories\WinnerAuditLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable, append-only audit trail of winner/claim/fulfilment events
 * (P10) — see docs/WINNER_CLAIM_P10.md "Audit". Written only from the
 * Winner domain services (WinnerService, WinnerLifecycleService,
 * WinnerClaimService, PrizeFulfilmentService). Never exposed by any
 * public/customer API endpoint.
 */
#[Fillable(['winner_id', 'event', 'from_status', 'to_status', 'actor_type', 'actor_id', 'metadata'])]
class WinnerAuditLog extends Model
{
    /** @use HasFactory<WinnerAuditLogFactory> */
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
     * @return BelongsTo<Winner, $this>
     */
    public function winner(): BelongsTo
    {
        return $this->belongsTo(Winner::class);
    }
}
