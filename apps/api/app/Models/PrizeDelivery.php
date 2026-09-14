<?php

namespace App\Models;

use App\Enums\PrizeDeliveryStatus;
use Database\Factories\PrizeDeliveryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Manual prize fulfilment tracking (P10) — see
 * docs/WINNER_CLAIM_P10.md "Fulfilment". At most one row per winner,
 * ever. `prize_id` is resolved server-side by
 * App\Services\Winner\PrizeResolutionService, never accepted from a
 * request. Written exclusively by
 * App\Services\Winner\PrizeFulfilmentService.
 */
#[Fillable(['winner_id', 'prize_id', 'status', 'delivery_method', 'tracking_reference', 'shipped_at', 'delivered_at', 'notes', 'metadata'])]
class PrizeDelivery extends Model
{
    /** @use HasFactory<PrizeDeliveryFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PrizeDeliveryStatus::class,
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
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

    /**
     * @return BelongsTo<Prize, $this>
     */
    public function prize(): BelongsTo
    {
        return $this->belongsTo(Prize::class);
    }
}
