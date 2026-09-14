<?php

namespace App\Events;

use App\Models\Competition;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by CompetitionLifecycleService::transition() — via
 * DB::afterCommit(), and only on the call that actually performs the
 * draw_in_progress -> drawn transition. This is the entire P9 -> P10
 * handoff: the winner must never exist before the draw result commits
 * (see docs/WINNER_CLAIM_P10.md "Draw -> winner handoff"), so this event
 * fires only once that commit has genuinely, durably happened. Mirrors
 * CompetitionDrawPending (the P8 -> P9 handoff) exactly.
 */
class CompetitionDrawn
{
    use Dispatchable;

    public function __construct(
        public readonly Competition $competition,
    ) {}
}
