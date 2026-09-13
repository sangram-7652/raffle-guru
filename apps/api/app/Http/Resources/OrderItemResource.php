<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'competition_id' => $this->competition_id,
            'competition_title' => $this->competition_title,
            'competition_slug' => $this->competition?->slug,
            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price,
            'currency' => $this->currency,
            'line_total' => $this->line_total,
        ];
    }
}
