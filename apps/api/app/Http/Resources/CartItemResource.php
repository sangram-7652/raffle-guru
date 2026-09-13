<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Explicit allow-list — see UserResource (P3) for the convention. Nests a
 * minimal competition summary (not the full CompetitionResource) — just
 * enough for the cart UI to render a line item without a second request.
 */
class CartItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price,
            'currency' => $this->currency,
            'line_total' => $this->lineTotal(),
            'competition' => [
                'id' => $this->competition->id,
                'slug' => $this->competition->slug,
                'title' => $this->competition->title,
                'image' => $this->competition->images[0] ?? null,
                'draw_at' => $this->competition->draw_at?->toIso8601String(),
            ],
        ];
    }
}
