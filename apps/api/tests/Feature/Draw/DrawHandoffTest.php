<?php

namespace Tests\Feature\Draw;

use App\Enums\CompetitionStatus;
use App\Jobs\DrawCompetitionJob;
use App\Models\Competition;
use App\Services\Competitions\CompetitionLifecycleService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * CompetitionLifecycleService::transition() fires
 * App\Events\CompetitionDrawPending via DB::afterCommit() only on the
 * call that actually performs closed -> draw_pending (see
 * docs/DRAW_ENGINE_P9.md "Trigger") — mirrors
 * tests/Feature/Ticket/PaymentHandoffTest.php (P7) exactly, including
 * the same reason for using DatabaseMigrations instead of
 * RefreshDatabase: afterCommit callbacks never fire inside
 * RefreshDatabase's always-rolled-back wrapping transaction, so this
 * specific behavior can only be tested honestly with real commits.
 */
class DrawHandoffTest extends TestCase
{
    use DatabaseMigrations;

    public function test_reaching_draw_pending_dispatches_the_draw_job_exactly_once(): void
    {
        Queue::fake();
        $competition = Competition::factory()->withStatus(CompetitionStatus::Closed)->create();

        app(CompetitionLifecycleService::class)->transition($competition, CompetitionStatus::DrawPending, 'draw_at reached');

        Queue::assertPushed(DrawCompetitionJob::class, 1);
    }

    public function test_an_already_draw_pending_competition_does_not_dispatch_again(): void
    {
        Queue::fake();
        $competition = Competition::factory()->withStatus(CompetitionStatus::DrawPending)->create();

        // Idempotent no-op — the matrix rejects closed's own target
        // status from a state that isn't closed.
        app(CompetitionLifecycleService::class)->transition($competition, CompetitionStatus::DrawPending, 'draw_at reached');

        Queue::assertNotPushed(DrawCompetitionJob::class);
    }

    public function test_transitioning_to_a_different_status_does_not_dispatch_a_draw_job(): void
    {
        Queue::fake();
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create();

        app(CompetitionLifecycleService::class)->transition($competition, CompetitionStatus::Closed, 'ends_at reached');

        Queue::assertNotPushed(DrawCompetitionJob::class);
    }

    public function test_the_dispatched_job_carries_the_correct_competition(): void
    {
        Queue::fake();
        $competition = Competition::factory()->withStatus(CompetitionStatus::Closed)->create();

        app(CompetitionLifecycleService::class)->transition($competition, CompetitionStatus::DrawPending, 'draw_at reached');

        Queue::assertPushed(DrawCompetitionJob::class, fn (DrawCompetitionJob $job) => $job->competition->is($competition));
    }
}
