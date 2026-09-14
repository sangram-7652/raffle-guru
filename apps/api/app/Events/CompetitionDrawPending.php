<?php

namespace App\Events;

use App\Models\Competition;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by CompetitionLifecycleService::transition() — via
 * DB::afterCommit(), and only on the call that actually performs the
 * closed -> draw_pending transition. This is the entire P8 -> P9
 * handoff: the lifecycle service knows nothing beyond "fire this
 * event"; it has no dependency on the draw engine. See
 * docs/DRAW_ENGINE_P9.md "Trigger" (mirrors OrderPaid, the P6 -> P7
 * handoff, exactly).
 */
class CompetitionDrawPending
{
    use Dispatchable;

    public function __construct(
        public readonly Competition $competition,
    ) {}
}
