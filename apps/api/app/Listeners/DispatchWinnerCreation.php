<?php

namespace App\Listeners;

use App\Events\CompetitionDrawn;
use App\Jobs\CreateWinnerJob;

/**
 * Deliberately a plain (non-queued) listener — mirrors
 * App\Listeners\DispatchDrawExecution (P9) exactly. Auto-discovered by
 * Laravel; do NOT also register this manually in a service provider
 * (would double-dispatch the job).
 */
class DispatchWinnerCreation
{
    public function handle(CompetitionDrawn $event): void
    {
        CreateWinnerJob::dispatch($event->competition);
    }
}
