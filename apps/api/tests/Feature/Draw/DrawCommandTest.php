<?php

namespace Tests\Feature\Draw;

use App\Enums\CompetitionStatus;
use App\Enums\DrawStatus;
use App\Models\Competition;
use App\Models\Draw;
use App\Models\DrawResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Draw\Concerns\CreatesDrawFixtures;
use Tests\TestCase;

class DrawCommandTest extends TestCase
{
    use CreatesDrawFixtures, RefreshDatabase;

    public function test_requires_a_competition_in_draw_pending_or_draw_in_progress(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create();

        $this->artisan('competition:draw', ['competition' => $competition->id])
            ->assertSuccessful();

        $this->assertSame(0, Draw::query()->where('competition_id', $competition->id)->count());
    }

    public function test_reports_an_error_for_an_unknown_competition(): void
    {
        $this->artisan('competition:draw', ['competition' => '999999'])
            ->assertFailed();
    }

    public function test_draws_a_competition_by_id(): void
    {
        ['competition' => $competition] = $this->createCompetitionWithTickets(CompetitionStatus::DrawPending, 4);

        $this->artisan('competition:draw', ['competition' => (string) $competition->id])
            ->assertSuccessful();

        $this->assertSame(CompetitionStatus::Drawn, $competition->fresh()->status);
        $this->assertSame(1, DrawResult::query()->where('competition_id', $competition->id)->count());
    }

    public function test_draws_a_competition_by_slug(): void
    {
        ['competition' => $competition] = $this->createCompetitionWithTickets(CompetitionStatus::DrawPending, 4);

        $this->artisan('competition:draw', ['competition' => $competition->slug])
            ->assertSuccessful();

        $this->assertSame(CompetitionStatus::Drawn, $competition->fresh()->status);
    }

    /**
     * #25 — duplicate command invocations must be safe: running it
     * again after a competition is already drawn does nothing further.
     */
    public function test_running_the_command_twice_on_the_same_competition_is_safe(): void
    {
        ['competition' => $competition] = $this->createCompetitionWithTickets(CompetitionStatus::DrawPending, 4);

        $this->artisan('competition:draw', ['competition' => (string) $competition->id])->assertSuccessful();
        $this->artisan('competition:draw', ['competition' => (string) $competition->id])->assertSuccessful();

        $this->assertSame(1, DrawResult::query()->where('competition_id', $competition->id)->count());
    }

    public function test_no_argument_sweeps_every_draw_pending_or_draw_in_progress_competition(): void
    {
        ['competition' => $a] = $this->createCompetitionWithTickets(CompetitionStatus::DrawPending, 3);
        ['competition' => $b] = $this->createCompetitionWithTickets(CompetitionStatus::DrawPending, 3);
        $untouched = Competition::factory()->withStatus(CompetitionStatus::Active)->create();

        $this->artisan('competition:draw')->assertSuccessful();

        $this->assertSame(CompetitionStatus::Drawn, $a->fresh()->status);
        $this->assertSame(CompetitionStatus::Drawn, $b->fresh()->status);
        $this->assertSame(CompetitionStatus::Active, $untouched->fresh()->status);
    }

    public function test_no_argument_sweep_recovers_a_stale_draw_in_progress_competition(): void
    {
        ['competition' => $competition] = $this->createCompetitionWithTickets(CompetitionStatus::DrawPending, 3);
        $competition->update(['status' => CompetitionStatus::DrawInProgress]);
        Draw::factory()->for($competition)->create([
            'status' => DrawStatus::Running,
            'attempt' => 1,
            'started_at' => now()->subMinutes(30),
        ]);

        $this->artisan('competition:draw')->assertSuccessful();

        $this->assertSame(CompetitionStatus::Drawn, $competition->fresh()->status);
        $this->assertSame(1, DrawResult::query()->where('competition_id', $competition->id)->count());
    }
}
