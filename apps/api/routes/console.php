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
