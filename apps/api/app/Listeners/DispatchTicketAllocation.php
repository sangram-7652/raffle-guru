<?php

namespace App\Listeners;

use App\Events\OrderPaid;
use App\Jobs\AllocateTicketsForOrder;

/**
 * Deliberately a plain (non-queued) listener — its only job is to
 * dispatch the already-queued AllocateTicketsForOrder job, so making the
 * listener itself queued would just add a pointless extra queue hop.
 */
class DispatchTicketAllocation
{
    public function handle(OrderPaid $event): void
    {
        AllocateTicketsForOrder::dispatch($event->order);
    }
}
