<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Explicit allow-list. `reference` is the only public identifier ever
 * used in a URL — the numeric `id` is included for the frontend's own
 * bookkeeping but routes always key off `reference` (see
 * OrderController::show).
 */
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status->value,
            'currency' => $this->currency,
            'subtotal' => $this->subtotal,
            'total' => $this->total,
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            // Latest attempt only (P6) — never a full payment history via
            // this resource. See docs/PAYMENTS_DESIGN_P6.md.
            'payment' => $this->whenLoaded(
                'latestPayment',
                fn () => $this->latestPayment ? new PaymentResource($this->latestPayment) : null,
            ),
            // Empty until allocation completes (P7) — the frontend
            // distinguishes "paid, allocation in progress" from
            // "allocated" by whether this is empty, not by a status
            // field. See docs/TICKET_ENGINE_DESIGN_P7.md.
            'entries' => EntryResource::collection($this->whenLoaded('entries')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
