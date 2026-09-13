<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Explicit allow-list — never gateway secrets, signatures, or raw
 * metadata. See docs/PAYMENTS_DESIGN_P6.md "Security model".
 */
class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'gateway' => $this->gateway,
            'status' => $this->status->value,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'failure_message' => $this->failure_message,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
