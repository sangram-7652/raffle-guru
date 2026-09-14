<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// P8 — competition lifecycle automation. everyMinute() is a simple,
// production-safe cadence for a business rule with no sub-minute
// precision requirement; withoutOverlapping() (Redis-backed cache
// mutex) stops the scheduler itself from stacking a second concurrent
// run — the real concurrency guarantee is the per-row lock inside
// CompetitionLifecycleService::transition(). See
// docs/COMPETITION_LIFECYCLE_P8.md.
Schedule::command('competition:lifecycle')
    ->everyMinute()
    ->withoutOverlapping();

// P9 — draw engine safety net. The PRIMARY trigger is event-driven, not
// this schedule: CompetitionLifecycleService fires CompetitionDrawPending
// the instant a competition reaches draw_pending, which a queued
// DrawCompetitionJob normally picks up within seconds (see
// docs/DRAW_ENGINE_P9.md "Trigger"). This sweep exists only to (a) catch
// a competition the queue handoff somehow missed, and (b) recover a
// competition stuck in draw_in_progress because its worker crashed
// mid-draw (see "Crash recovery") — both handled the same idempotent
// way DrawService::run() already handles the queued job. A 5-minute
// cadence is appropriate for a safety net, not a primary trigger.
Schedule::command('competition:draw')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// P10 — winner creation safety net. The PRIMARY trigger is event-driven:
// CompetitionLifecycleService fires CompetitionDrawn the instant a
// competition reaches drawn, which a queued CreateWinnerJob normally
// picks up within seconds (see docs/WINNER_CLAIM_P10.md "Draw -> winner
// handoff"). This sweep exists only to catch a competition the queue
// handoff missed (e.g. a worker crash between commit and job execution)
// — same reasoning as the competition:draw sweep above.
Schedule::command('winners:reconcile')
    ->everyFiveMinutes()
    ->withoutOverlapping();
