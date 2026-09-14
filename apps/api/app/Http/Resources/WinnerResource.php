<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Explicit allow-list — the caller (App\Http\Controllers\Api\V1\Account\
 * WinnerController) guarantees `$this->user_id === $request->user()->id`
 * before this is ever built, so this resource is only ever the
 * authenticated user's own winner record. Never exposes another
 * winner's identity, contact info, KYC data, delivery notes, or
 * internal audit logs — see docs/WINNER_CLAIM_P10.md "Private data".
 */
class WinnerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'competition' => [
                'id' => $this->competition->id,
                'slug' => $this->competition->slug,
                'title' => $this->competition->title,
            ],
            'winning_ticket_number' => $this->drawResult->ticket_number,
            'prize' => $this->resolvePrizeForDisplay(),
            'contacted_at' => $this->contacted_at?->toIso8601String(),
            'verified_at' => $this->verified_at?->toIso8601String(),
            'claim_approved_at' => $this->claim_approved_at?->toIso8601String(),
            'delivery_pending_at' => $this->delivery_pending_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'claim' => $this->whenLoaded('claim', fn () => $this->claim ? new WinnerClaimResource($this->claim) : null),
            'delivery' => $this->whenLoaded('delivery', fn () => $this->delivery ? new PrizeDeliveryResource($this->delivery) : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * Best-effort DISPLAY of the prize, so the customer can see what
     * they've won as soon as they view their winner record — not the
     * authoritative resolution used to create a delivery (that stays
     * exclusively PrizeResolutionService's job, called only at
     * delivery-creation time). Prefers the delivery's already-resolved
     * prize once one exists; before that, falls back to the
     * competition's own single prize when unambiguous. Never guesses
     * when the competition has more than one prize — see
     * docs/WINNER_CLAIM_P10.md "Prize resolution".
     *
     * @return array<string, mixed>|null
     */
    private function resolvePrizeForDisplay(): ?array
    {
        $prize = $this->delivery?->prize;

        if ($prize === null && $this->relationLoaded('competition') && $this->competition->relationLoaded('prizes')) {
            $prizes = $this->competition->prizes;
            $prize = $prizes->count() === 1 ? $prizes->first() : null;
        }

        return $prize ? [
            'title' => $prize->title,
            'value' => $prize->value,
            'currency' => $prize->currency,
        ] : null;
    }
}
