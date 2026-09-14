<?php

namespace Tests\Feature\Competition;

use App\Enums\CompetitionStatus;
use App\Models\Competition;
use App\Models\CompetitionLifecycleEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LifecycleCommandTest extends TestCase
{
    use RefreshDatabase;

    // -- Time-based activation --------------------------------------------------

    public function test_scheduled_competition_before_starts_at_stays_scheduled(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Scheduled)->create([
            'starts_at' => now()->addHour(),
        ]);

        $this->artisan('competition:lifecycle')->assertExitCode(0);

        $this->assertSame(CompetitionStatus::Scheduled, $competition->fresh()->status);
    }

    public function test_scheduled_competition_exactly_at_starts_at_becomes_active(): void
    {
        // Freeze "now" so the boundary comparison is exact, not
        // best-effort — starts_at <= now must include equality.
        $this->travelTo($now = now());
        $competition = Competition::factory()->withStatus(CompetitionStatus::Scheduled)->create([
            'starts_at' => $now,
        ]);

        $this->artisan('competition:lifecycle')->assertExitCode(0);

        $this->assertSame(CompetitionStatus::Active, $competition->fresh()->status);
    }

    public function test_scheduled_competition_after_starts_at_becomes_active(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Scheduled)->create([
            'starts_at' => now()->subDay(),
        ]);

        $this->artisan('competition:lifecycle')->assertExitCode(0);

        $this->assertSame(CompetitionStatus::Active, $competition->fresh()->status);
    }

    // -- Idempotency --------------------------------------------------

    public function test_lifecycle_command_is_idempotent_across_repeated_runs(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Scheduled)->create([
            'starts_at' => now()->subMinute(),
        ]);

        $this->artisan('competition:lifecycle')->assertExitCode(0);
        $this->artisan('competition:lifecycle')->assertExitCode(0);
        $this->artisan('competition:lifecycle')->assertExitCode(0);

        $this->assertSame(CompetitionStatus::Active, $competition->fresh()->status);
        $this->assertSame(1, CompetitionLifecycleEvent::query()->where('competition_id', $competition->id)->count());
    }

    public function test_lifecycle_processing_handles_a_mix_of_already_processed_and_pending_competitions(): void
    {
        $alreadyActive = Competition::factory()->withStatus(CompetitionStatus::Active)->create();
        $due = Competition::factory()->withStatus(CompetitionStatus::Scheduled)->create(['starts_at' => now()->subMinute()]);
        $notYetDue = Competition::factory()->withStatus(CompetitionStatus::Scheduled)->create(['starts_at' => now()->addDay()]);

        $this->artisan('competition:lifecycle')->assertExitCode(0);

        $this->assertSame(CompetitionStatus::Active, $alreadyActive->fresh()->status);
        $this->assertSame(CompetitionStatus::Active, $due->fresh()->status);
        $this->assertSame(CompetitionStatus::Scheduled, $notYetDue->fresh()->status);
    }

    // -- ends_at closing --------------------------------------------------

    public function test_ends_at_closes_an_active_competition(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create([
            'ends_at' => now()->subMinute(),
        ]);

        $this->artisan('competition:lifecycle')->assertExitCode(0);

        $this->assertSame(CompetitionStatus::Closed, $competition->fresh()->status);
    }

    public function test_an_active_competition_with_no_ends_at_never_auto_closes_on_time(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create([
            'ends_at' => null,
        ]);

        $this->artisan('competition:lifecycle')->assertExitCode(0);

        $this->assertSame(CompetitionStatus::Active, $competition->fresh()->status);
    }

    public function test_an_active_competition_with_a_future_ends_at_stays_active(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create([
            'ends_at' => now()->addDay(),
        ]);

        $this->artisan('competition:lifecycle')->assertExitCode(0);

        $this->assertSame(CompetitionStatus::Active, $competition->fresh()->status);
    }

    // -- sold_out -> closed --------------------------------------------------

    public function test_sold_out_competition_becomes_closed_unconditionally(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::SoldOut)->create();

        $this->artisan('competition:lifecycle')->assertExitCode(0);

        $this->assertSame(CompetitionStatus::Closed, $competition->fresh()->status);
    }

    // -- closed -> draw_pending --------------------------------------------------

    public function test_closed_competition_past_draw_at_becomes_draw_pending(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Closed)->create([
            'draw_at' => now()->subMinute(),
        ]);

        $this->artisan('competition:lifecycle')->assertExitCode(0);

        $this->assertSame(CompetitionStatus::DrawPending, $competition->fresh()->status);
    }

    public function test_closed_competition_before_draw_at_stays_closed(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Closed)->create([
            'draw_at' => now()->addDay(),
        ]);

        $this->artisan('competition:lifecycle')->assertExitCode(0);

        $this->assertSame(CompetitionStatus::Closed, $competition->fresh()->status);
    }

    public function test_closed_competition_with_no_draw_at_never_auto_advances(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Closed)->create([
            'draw_at' => null,
        ]);

        $this->artisan('competition:lifecycle')->assertExitCode(0);

        $this->assertSame(CompetitionStatus::Closed, $competition->fresh()->status);
    }

    // -- Closed competitions never reactivate --------------------------------------------------

    public function test_closed_competition_cannot_become_active_again(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Closed)->create([
            'starts_at' => now()->subDay(),
        ]);

        $this->artisan('competition:lifecycle')->assertExitCode(0);

        $this->assertSame(CompetitionStatus::Closed, $competition->fresh()->status);
    }

    // -- A full lifecycle sweep in one run reaches draw_pending in one pass --------------------------------------------------

    public function test_a_single_run_can_carry_a_sold_out_competition_all_the_way_to_draw_pending(): void
    {
        // sold_out -> closed -> draw_pending are each independent
        // queries within the same command invocation; a competition
        // already past its draw_at when it's swept should reach
        // draw_pending in one command run, not require three.
        $competition = Competition::factory()->withStatus(CompetitionStatus::SoldOut)->create([
            'draw_at' => now()->subMinute(),
        ]);

        $this->artisan('competition:lifecycle')->assertExitCode(0);

        $this->assertSame(CompetitionStatus::DrawPending, $competition->fresh()->status);
    }
}
