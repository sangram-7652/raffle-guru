<?php

namespace App\Listeners;

use App\Events\CompetitionDrawPending;
use App\Jobs\DrawCompetitionJob;

/**
 * Deliberately a plain (non-queued) listener — its only job is to
 * dispatch the already-queued DrawCompetitionJob, so making the
 * listener itself queued would just add a pointless extra queue hop.
 * Mirrors App\Listeners\DispatchTicketAllocation (P7) exactly.
 *
 * Auto-discovered by Laravel (any app/Listeners/* class with a
 * type-hinted handle() parameter) — do NOT also register this manually
 * in a service provider, which would double-dispatch the job (see the
 * P7 incident documented in docs/TICKET_ENGINE_DESIGN_P7.md).
 */
class DispatchDrawExecution
{
    public function handle(CompetitionDrawPending $event): void
    {
        DrawCompetitionJob::dispatch($event->competition);
    }
}
