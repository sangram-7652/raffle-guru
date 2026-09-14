<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Explicit allow-list. `ticket_numbers` are the customer-facing raffle
 * numbers (unique per competition — see
 * docs/TICKET_ENGINE_DESIGN_P7.md); never the internal `tickets.id`.
 *
 * `competition.status` (P9) is the only lifecycle-related addition this
 * phase makes here — the minimum needed for the account entries page to
 * read-only reflect "this competition has been drawn", per
 * docs/DRAW_ENGINE_P9.md "Customer account". It deliberately does NOT
 * say whether this entry's own tickets won: that is winner
 * identification/publishing, which is explicitly P10's, not P9's.
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
                'status' => $this->competition->status->value,
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
