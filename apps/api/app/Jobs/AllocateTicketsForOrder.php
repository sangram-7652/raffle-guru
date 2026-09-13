<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\Tickets\TicketAllocationException;
use App\Services\Tickets\TicketAllocationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Idempotent by design — see docs/TICKET_ENGINE_DESIGN_P7.md
 * "Idempotency strategy" / "Queue/job behavior". Running this job 0, 1,
 * or many times for the same order always converges to the same end
 * state; TicketAllocationService owns that guarantee, this job just
 * calls it and handles the two possible outcomes.
 */
class AllocateTicketsForOrder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Matches the queue worker's own --tries=3 (see
     * infrastructure/docker-compose.yml) — explicit here too so the
     * job behaves the same regardless of how the worker is invoked.
     */
    public int $tries = 3;

    public function __construct(
        public readonly Order $order,
    ) {}

    public function handle(TicketAllocationService $allocations): void
    {
        try {
            $allocations->allocateOrder($this->order);
        } catch (TicketAllocationException $e) {
            // A capacity/per-user-limit conflict is deterministic —
            // retrying changes nothing. Fail permanently (lands in
            // failed_jobs) instead of burning through retry attempts.
            // See docs/TICKET_ENGINE_DESIGN_P7.md "What happens when a
            // paid order can't be allocated".
            $this->fail($e);

            return;
        }

        if ($this->attempts() > 1) {
            Log::info('ticket.allocation.retried', [
                'order_id' => $this->order->id,
                'attempt' => $this->attempts(),
            ]);
        }
    }
}
