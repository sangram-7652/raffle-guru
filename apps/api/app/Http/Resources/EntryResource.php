<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Explicit allow-list. `ticket_numbers` are the customer-facing raffle
 * numbers (unique per competition — see
 * docs/TICKET_ENGINE_DESIGN_P7.md); never the internal `tickets.id`.
 * No draw/RNG internals exist to leak, and none will (P9 only).
 */
class EntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'competition' => [
                'id' => $this->competition->id,
                'slug' => $this->competition->slug,
                'title' => $this->competition->title,
            ],
            'order_reference' => $this->order->reference,
            'quantity' => $this->quantity,
            'ticket_numbers' => $this->whenLoaded(
                'tickets',
                fn () => $this->tickets->pluck('ticket_number')->sort()->values(),
            ),
            'allocated_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
