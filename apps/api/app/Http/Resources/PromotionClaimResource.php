<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Scoped to the owning customer's own claim only (see
 * Account\PromotionClaimController) — never exposes another user's
 * data, the internal promotion/order/subscriber ids, or a raw
 * exception message (`failure_reason` is always one of the fixed,
 * customer-safe strings PromotionClaimService writes).
 */
class PromotionClaimResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'status' => $this->status->value,
            'failure_reason' => $this->failure_reason,
            'entry_id' => $this->entry_id,
        ];
    }
}
