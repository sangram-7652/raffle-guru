<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Explicit allow-list — see UserResource (P3) for the same convention.
 */
class PrizeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'value' => $this->value,
            'cash_alternative_value' => $this->cash_alternative_value,
            'currency' => $this->currency,
            'quantity' => $this->quantity,
        ];
    }
}
