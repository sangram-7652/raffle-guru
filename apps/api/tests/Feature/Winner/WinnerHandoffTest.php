<?php

namespace Tests\Feature\Winner;

use App\Enums\CompetitionStatus;
use App\Jobs\CreateWinnerJob;
use App\Models\Competition;
use App\Services\Competitions\CompetitionLifecycleService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * CompetitionLifecycleService::transition() fires App\Events\
 * CompetitionDrawn via DB::afterCommit() only on the call that actually
 * performs draw_in_progress -> drawn (see
 * docs/WINNER_CLAIM_P10.md "Draw -> winner handoff") — mirrors
 * tests/Feature/Draw/DrawHandoffTest.php (P9) exactly, including using
 * DatabaseMigrations rather than RefreshDatabase since afterCommit
 * callbacks never fire inside RefreshDatabase's rolled-back transaction.
 */
class WinnerHandoffTest extends TestCase
{
    use DatabaseMigrations;

    public function test_reaching_drawn_dispatches_the_winner_job_exactly_once(): void
    {
        Queue::fake();
        $competition = Competition::factory()->withStatus(CompetitionStatus::DrawInProgress)->create();

        app(CompetitionLifecycleService::class)->transition($competition, CompetitionStatus::Drawn, 'draw completed');

        Queue::assertPushed(CreateWinnerJob::class, 1);
    }

    public function test_an_already_drawn_competition_does_not_dispatch_again(): void
    {
        Queue::fake();
        $competition = Competition::factory()->withStatus(CompetitionStatus::Drawn)->create();

        app(CompetitionLifecycleService::class)->transition($competition, CompetitionStatus::Drawn, 'draw completed');

        Queue::assertNotPushed(CreateWinnerJob::class);
    }

    public function test_transitioning_to_a_different_status_does_not_dispatch_a_winner_job(): void
    {
        Queue::fake();
        $competition = Competition::factory()->withStatus(CompetitionStatus::Closed)->create();

        app(CompetitionLifecycleService::class)->transition($competition, CompetitionStatus::DrawPending, 'draw_at reached');

        Queue::assertNotPushed(CreateWinnerJob::class);
    }

    public function test_the_dispatched_job_carries_the_correct_competition(): void
    {
        Queue::fake();
        $competition = Competition::factory()->withStatus(CompetitionStatus::DrawInProgress)->create();

        app(CompetitionLifecycleService::class)->transition($competition, CompetitionStatus::Drawn, 'draw completed');

        Queue::assertPushed(CreateWinnerJob::class, fn (CreateWinnerJob $job) => $job->competition->is($competition));
    }

    public function test_drawn_to_completed_is_a_valid_matrix_edge(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Drawn)->create();

        $result = app(CompetitionLifecycleService::class)->transition($competition, CompetitionStatus::Completed, 'winner completed');

        $this->assertTrue($result);
        $this->assertSame(CompetitionStatus::Completed, $competition->fresh()->status);
    }

    #[DataProvider('invalidCompletionSourceProvider')]
    public function test_only_drawn_can_reach_completed(CompetitionStatus $from): void
    {
        $competition = Competition::factory()->withStatus($from)->create();

        $result = app(CompetitionLifecycleService::class)->transition($competition, CompetitionStatus::Completed, 'attempted shortcut');

        $this->assertFalse($result);
        $this->assertSame($from, $competition->fresh()->status);
    }

    /**
     * @return array<string, array{0: CompetitionStatus}>
     */
    public static function invalidCompletionSourceProvider(): array
    {
        return [
            'active' => [CompetitionStatus::Active],
            'closed' => [CompetitionStatus::Closed],
            'draw_pending' => [CompetitionStatus::DrawPending],
            'draw_in_progress' => [CompetitionStatus::DrawInProgress],
        ];
    }
}
