<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by PaymentService::markPaid() — via DB::afterCommit(), and only
 * on the call that actually performs the pending/payment_pending → paid
 * transition (never on the already-paid idempotent no-op branch). This
 * is the entire P6 → P7 handoff: PaymentService knows nothing beyond
 * "fire this event"; it has no dependency on entries/tickets/allocation.
 * See docs/TICKET_ENGINE_DESIGN_P7.md "Order/payment handoff".
 */
class OrderPaid
{
    use Dispatchable;

    public function __construct(
        public readonly Order $order,
    ) {}
}
