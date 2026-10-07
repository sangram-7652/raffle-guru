<?php

namespace Tests\Feature\Competition;

use App\Enums\CompetitionStatus;
use App\Events\CompetitionDrawPending;
use App\Models\Competition;
use App\Models\CompetitionLifecycleEvent;
use App\Services\Competitions\CompetitionLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): CompetitionLifecycleService
    {
        return app(CompetitionLifecycleService::class);
    }

    // -- Valid transitions --------------------------------------------------

    public function test_scheduled_transitions_to_active(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Scheduled)->create();

        $result = $this->service()->transition($competition, CompetitionStatus::Active, 'starts_at reached');

        $this->assertTrue($result);
        $this->assertSame(CompetitionStatus::Active, $competition->fresh()->status);
    }

    public function test_active_transitions_to_sold_out(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create();

        $this->assertTrue($this->service()->transition($competition, CompetitionStatus::SoldOut, 'capacity reached'));
        $this->assertSame(CompetitionStatus::SoldOut, $competition->fresh()->status);
    }

    public function test_active_transitions_to_closed(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create();

        $this->assertTrue($this->service()->transition($competition, CompetitionStatus::Closed, 'ends_at reached'));
        $this->assertSame(CompetitionStatus::Closed, $competition->fresh()->status);
    }

    public function test_sold_out_transitions_to_closed(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::SoldOut)->create();

        $this->assertTrue($this->service()->transition($competition, CompetitionStatus::Closed, 'sold out'));
        $this->assertSame(CompetitionStatus::Closed, $competition->fresh()->status);
    }

    public function test_closed_transitions_to_draw_pending(): void
    {
        // Real P9 handoff fires DispatchDrawExecution -> DrawCompetitionJob,
        // which runs inline under the test env's QUEUE_CONNECTION=sync and
        // carries the competition past draw_pending into draw_in_progress —
        // this test only verifies the transition the service itself makes,
        // same isolation CompetitionLifecycleActionTest already uses for the
        // identical transition.
        Event::fake([CompetitionDrawPending::class]);

        $competition = Competition::factory()->withStatus(CompetitionStatus::Closed)->create();

        $this->assertTrue($this->service()->transition($competition, CompetitionStatus::DrawPending, 'draw_at reached'));
        $this->assertSame(CompetitionStatus::DrawPending, $competition->fresh()->status);
    }

    // -- P9 additions (draw engine owns these two edges) ---------------------

    public function test_draw_pending_transitions_to_draw_in_progress(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::DrawPending)->create();

        $this->assertTrue($this->service()->transition($competition, CompetitionStatus::DrawInProgress, 'draw acquired'));
        $this->assertSame(CompetitionStatus::DrawInProgress, $competition->fresh()->status);
    }

    public function test_draw_in_progress_transitions_to_drawn(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::DrawInProgress)->create();

        $this->assertTrue($this->service()->transition($competition, CompetitionStatus::Drawn, 'draw completed'));
        $this->assertSame(CompetitionStatus::Drawn, $competition->fresh()->status);
    }

    // -- P10 addition (winner/claim/fulfilment engine owns this edge) --------

    public function test_draft_transitions_to_scheduled(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Draft)->create();

        $this->assertTrue($this->service()->transition($competition, CompetitionStatus::Scheduled, 'publish'));
        $this->assertSame(CompetitionStatus::Scheduled, $competition->fresh()->status);
    }

    public function test_drawn_transitions_to_completed(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Drawn)->create();

        $this->assertTrue($this->service()->transition($competition, CompetitionStatus::Completed, 'winner completed'));
        $this->assertSame(CompetitionStatus::Completed, $competition->fresh()->status);
    }

    // -- Invalid transitions --------------------------------------------------

    public static function invalidTransitionProvider(): array
    {
        return [
            'draft -> active' => [CompetitionStatus::Draft, CompetitionStatus::Active],
            'draft -> drawn' => [CompetitionStatus::Draft, CompetitionStatus::Drawn],
            'scheduled -> drawn' => [CompetitionStatus::Scheduled, CompetitionStatus::Drawn],
            'scheduled -> closed' => [CompetitionStatus::Scheduled, CompetitionStatus::Closed],
            'active -> drawn' => [CompetitionStatus::Active, CompetitionStatus::Drawn],
            'active -> draw_pending' => [CompetitionStatus::Active, CompetitionStatus::DrawPending],
            'active -> scheduled' => [CompetitionStatus::Active, CompetitionStatus::Scheduled],
            'sold_out -> active' => [CompetitionStatus::SoldOut, CompetitionStatus::Active],
            'sold_out -> draw_pending' => [CompetitionStatus::SoldOut, CompetitionStatus::DrawPending],
            'sold_out -> drawn' => [CompetitionStatus::SoldOut, CompetitionStatus::Drawn],
            'closed -> active' => [CompetitionStatus::Closed, CompetitionStatus::Active],
            'closed -> drawn' => [CompetitionStatus::Closed, CompetitionStatus::Drawn],
            'closed -> draw_in_progress' => [CompetitionStatus::Closed, CompetitionStatus::DrawInProgress],
            'closed -> completed' => [CompetitionStatus::Closed, CompetitionStatus::Completed],
            'draw_pending -> drawn' => [CompetitionStatus::DrawPending, CompetitionStatus::Drawn],
            'draw_pending -> completed' => [CompetitionStatus::DrawPending, CompetitionStatus::Completed],
            // draw_pending -> draw_in_progress is now valid (P9) — see
            // test_draw_pending_transitions_to_draw_in_progress below.
            'draw_in_progress -> draw_pending' => [CompetitionStatus::DrawInProgress, CompetitionStatus::DrawPending],
            'draw_in_progress -> completed' => [CompetitionStatus::DrawInProgress, CompetitionStatus::Completed],
            // draw_in_progress -> drawn is now valid (P9) — see
            // test_draw_in_progress_transitions_to_drawn below.
            // drawn -> completed is now valid (P10) — see
            // test_drawn_transitions_to_completed above.
            'drawn -> draw_pending' => [CompetitionStatus::Drawn, CompetitionStatus::DrawPending],
        ];
    }

    #[DataProvider('invalidTransitionProvider')]
    public function test_invalid_transitions_are_rejected(CompetitionStatus $from, CompetitionStatus $to): void
    {
        $competition = Competition::factory()->withStatus($from)->create();

        $result = $this->service()->transition($competition, $to, 'attempted');

        $this->assertFalse($result);
        $this->assertSame($from, $competition->fresh()->status);
        $this->assertSame(0, CompetitionLifecycleEvent::query()->where('competition_id', $competition->id)->count());
    }

    // -- Only P10's one defined edge reaches `completed` (P9/P10 boundary) ---

    /**
     * Supersedes test_no_status_can_ever_reach_completed (the P9-scoped
     * version of this test, which asserted that NOTHING could ever
     * reach `completed` — that was only ever true because P10 hadn't
     * opened its one edge yet, not because `completed` is permanently
     * unreachable). P10 legitimately opens exactly one edge into it,
     * from `drawn` (see test_drawn_transitions_to_completed above,
     * and docs/WINNER_CLAIM_P10.md "Competition completion" for why —
     * only after the winner itself has been delivered, via
     * App\Services\Winner\PrizeFulfilmentService, never automatically
     * right after the draw). What must remain permanently true is that
     * every OTHER state stays unable to reach it.
     */
    public function test_only_drawn_can_reach_completed(): void
    {
        foreach (CompetitionStatus::cases() as $from) {
            $this->assertSame(
                $from === CompetitionStatus::Drawn,
                $this->service()->canTransition($from, CompetitionStatus::Completed),
                "{$from->value} -> completed reachability is wrong.",
            );
        }
    }

    /**
     * Regression guard for P8's own matrix subset: only the two P9
     * additions above may reach draw_in_progress/drawn, and only from
     * exactly the states P9 defines (draw_pending and draw_in_progress
     * respectively) — nothing else gained a path to them as a side
     * effect of adding those two edges.
     */
    public function test_only_the_p9_defined_states_can_reach_draw_in_progress_or_drawn(): void
    {
        foreach (CompetitionStatus::cases() as $from) {
            $canReachDrawInProgress = $this->service()->canTransition($from, CompetitionStatus::DrawInProgress);
            $canReachDrawn = $this->service()->canTransition($from, CompetitionStatus::Drawn);

            $this->assertSame($from === CompetitionStatus::DrawPending, $canReachDrawInProgress);
            $this->assertSame($from === CompetitionStatus::DrawInProgress, $canReachDrawn);
        }
    }

    // -- Idempotency --------------------------------------------------

    public function test_repeating_an_already_applied_transition_is_a_safe_no_op(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Scheduled)->create();

        $this->assertTrue($this->service()->transition($competition, CompetitionStatus::Active, 'starts_at reached'));
        $second = $this->service()->transition($competition->fresh(), CompetitionStatus::Active, 'starts_at reached');

        $this->assertFalse($second);
        $this->assertSame(CompetitionStatus::Active, $competition->fresh()->status);
        $this->assertSame(1, CompetitionLifecycleEvent::query()->where('competition_id', $competition->id)->count());
    }

    // -- Audit trail --------------------------------------------------

    public function test_a_successful_transition_writes_exactly_one_audit_event(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Scheduled)->create();

        $this->service()->transition($competition, CompetitionStatus::Active, 'starts_at reached');

        $event = CompetitionLifecycleEvent::query()->where('competition_id', $competition->id)->first();
        $this->assertNotNull($event);
        $this->assertSame('scheduled', $event->from_status);
        $this->assertSame('active', $event->to_status);
        $this->assertSame('starts_at reached', $event->reason);
    }

    public function test_never_trusts_a_stale_in_memory_competition_instance(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Scheduled)->create();
        $stale = Competition::find($competition->id);

        // Mutate the real row out from under the in-memory $stale copy.
        $competition->update(['status' => CompetitionStatus::Active]);

        // $stale->status is still "scheduled" in PHP memory, but the
        // service must re-read the row under lock rather than trust it.
        $result = $this->service()->transition($stale, CompetitionStatus::Active, 'starts_at reached');

        $this->assertFalse($result, 'Re-applying scheduled -> active against an already-active row must no-op.');
        // No audit event either — nothing actually transitioned. The raw
        // update() above (not going through the service) correctly
        // wrote none, and this no-op call must not write one either.
        $this->assertSame(0, CompetitionLifecycleEvent::query()->where('competition_id', $competition->id)->count());
    }
}
