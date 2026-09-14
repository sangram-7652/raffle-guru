<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Explicit allow-list. Deliberately omits `notes` (operator-only — see
 * docs/WINNER_CLAIM_P10.md "Privacy") and `metadata`. `prize_id` itself
 * is not exposed as a raw id; the parent WinnerResource embeds the
 * resolved Prize's own public fields instead.
 */
class PrizeDeliveryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'status' => $this->status->value,
            'delivery_method' => $this->delivery_method,
            'tracking_reference' => $this->tracking_reference,
            'shipped_at' => $this->shipped_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
        ];
    }
}
