<?php

namespace App\Services\Draw;

use App\Models\Ticket;
use Illuminate\Support\Carbon;

/**
 * The outcome of a strategy's winner selection — everything DrawService
 * needs to persist a DrawResult and the Draw row's audit fields,
 * without DrawService needing to know how the selection was actually
 * made (see App\Services\Draw\Strategies\DrawStrategy).
 */
final readonly class DrawSelection
{
    public function __construct(
        public Ticket $ticket,
        public int $eligibleTicketCount,
        public string $eligibilityVersion,
        public string $snapshotChecksum,
        public Carbon $snapshotAt,
        public string $randomnessMethod,
    ) {}
}
