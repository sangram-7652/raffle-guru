<?php

namespace Tests\Feature\Backup;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * P12.2 — `backup:run` is registered on the existing Laravel scheduler
 * (routes/console.php), the same one P8/P9/P10 already use, with its own
 * overlap guard. No MySQL needed: runs in the fast SQLite job.
 */
class BackupSchedulerTest extends TestCase
{
    public function test_backup_run_is_scheduled_daily_without_overlapping(): void
    {
        $schedule = $this->app->make(Schedule::class);

        $event = (new Collection($schedule->events()))
            ->first(fn ($event) => str_contains($event->command, 'backup:run'));

        $this->assertNotNull($event, 'backup:run is not registered on the scheduler.');
        $this->assertSame('0 2 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
