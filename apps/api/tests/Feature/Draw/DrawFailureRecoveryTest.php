<?php

namespace Tests\Feature\Draw;

use App\Enums\CompetitionStatus;
use App\Enums\DrawStatus;
use App\Models\Competition;
use App\Models\Draw;
use App\Models\DrawAuditLog;
use App\Models\DrawResult;
use App\Services\Draw\DrawService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Draw\Concerns\CreatesDrawFixtures;
use Tests\TestCase;

/**
 * Test matrix items 30-33: failure handling + crash recovery.
 */
class DrawFailureRecoveryTest extends TestCase
{
    use CreatesDrawFixtures, RefreshDatabase;

    private function service(): DrawService
    {
        return app(DrawService::class);
    }

    public function test_selection_failure_is_recorded_on_the_draw_row(): void
    {
        ['competition' => $competition] = $this->createCompetitionWithTickets(CompetitionStatus::DrawPending, 0, refundedCount: 4);

        try {
            $this->service()->run($competition);
            $this->fail('Expected a NoEligibleTicketsException.');
        } catch (\App\Services\Draw\NoEligibleTicketsException) {
            // expected
        }

        $draw = Draw::query()->where('competition_id', $competition->id)->sole();
        $this->assertSame(DrawStatus::Failed, $draw->status);
        $this->assertNotNull($draw->failed_at);
        $this->assertNotNull($draw->failure_reason);
    }

    public function test_competition_is_not_falsely_marked_drawn_after_a_failed_attempt(): void
    {
        ['competition' => $competition] = $this->createCompetitionWithTickets(CompetitionStatus::DrawPending, 0, refundedCount: 2);

        try {
            $this->service()->run($competition);
        } catch (\App\Services\Draw\NoEligibleTicketsException) {
            // expected
        }

        $this->assertSame(CompetitionStatus::DrawInProgress, $competition->fresh()->status);
        $this->assertSame(0, DrawResult::query()->where('competition_id', $competition->id)->count());
    }

    /**
     * The core crash-recovery scenario: a worker acquired the draw
     * (draw_pending -> draw_in_progress, a `draws` row created) and then
     * simply died — no exception, no failure record, nothing. Simulated
     * here by manually backdating a running attempt's started_at past
     * the lease window, exactly what would exist on disk after a real
     * crash.
     */
    public function test_a_stale_running_attempt_is_recovered_and_a_fresh_attempt_completes(): void
    {
        ['competition' => $competition] = $this->createCompetitionWithTickets(CompetitionStatus::DrawPending, 5);

        // Simulate: a first worker legitimately acquired the draw...
        $competition->update(['status' => CompetitionStatus::DrawInProgress]);
        $staleAttempt = Draw::factory()->for($competition)->create([
            'status' => DrawStatus::Running,
            'attempt' => 1,
            'started_at' => now()->subMinutes(20), // older than the 900s/15min lease
        ]);

        // ...then crashed. A later call (queue retry / scheduler sweep /
        // operator command) must recover it rather than get stuck
        // forever.
        $draw = $this->service()->run($competition->fresh());

        $this->assertNotNull($draw, 'A stale attempt must be recoverable, not a permanent dead end.');
        $this->assertSame(2, $draw->attempt);
        $this->assertSame(DrawStatus::Completed, $draw->status);
        $this->assertSame(CompetitionStatus::Drawn, $competition->fresh()->status);

        $staleAttempt->refresh();
        $this->assertSame(DrawStatus::Failed, $staleAttempt->status);
        $this->assertStringContainsString('stale', $staleAttempt->failure_reason);
    }

    public function test_a_recently_started_running_attempt_is_not_stolen_as_stale(): void
    {
        ['competition' => $competition] = $this->createCompetitionWithTickets(CompetitionStatus::DrawPending, 5);
        $competition->update(['status' => CompetitionStatus::DrawInProgress]);
        Draw::factory()->for($competition)->create([
            'status' => DrawStatus::Running,
            'attempt' => 1,
            'started_at' => now()->subMinutes(2), // well within the lease
        ]);

        $draw = $this->service()->run($competition->fresh());

        $this->assertNull($draw, 'A live (non-stale) attempt must not be pre-empted by another call.');
        $this->assertSame(1, Draw::query()->where('competition_id', $competition->id)->count());
    }

    /**
     * Retrying must preserve, never overwrite, the full audit history —
     * both the failed attempt row itself and its own audit log entries
     * survive the recovery untouched.
     */
    public function test_retry_preserves_the_previous_failed_attempts_audit_trail(): void
    {
        ['competition' => $competition] = $this->createCompetitionWithTickets(CompetitionStatus::DrawPending, 5);
        $competition->update(['status' => CompetitionStatus::DrawInProgress]);
        $staleAttempt = Draw::factory()->for($competition)->create([
            'status' => DrawStatus::Running,
            'attempt' => 1,
            'started_at' => now()->subMinutes(20),
        ]);
        DrawAuditLog::factory()->for($staleAttempt)->for($competition)->create(['event' => 'draw.started']);
        $originalAuditCount = DrawAuditLog::query()->where('draw_id', $staleAttempt->id)->count();

        $this->service()->run($competition->fresh());

        $this->assertSame(
            $originalAuditCount + 1, // +1 for the new 'draw.stale_attempt_recovered' entry
            DrawAuditLog::query()->where('draw_id', $staleAttempt->id)->count(),
            'The stale attempt\'s own audit rows must never be deleted or altered — only appended to.',
        );
        $this->assertDatabaseHas('draws', ['id' => $staleAttempt->id, 'status' => DrawStatus::Failed->value]);
    }
}
