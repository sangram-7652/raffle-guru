<?php

namespace Tests\Feature\Winner;

use App\Enums\CompetitionStatus;
use App\Models\Competition;
use App\Models\Winner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Winner\Concerns\CreatesWinnerFixtures;
use Tests\TestCase;

class WinnerReconcileCommandTest extends TestCase
{
    use CreatesWinnerFixtures, RefreshDatabase;

    public function test_creates_missing_winner_for_a_drawn_competition(): void
    {
        ['competition' => $competition] = $this->createDrawnCompetition();

        $this->artisan('winners:reconcile')->assertSuccessful();

        $this->assertDatabaseHas('winners', ['competition_id' => $competition->id]);
    }

    public function test_skips_a_competition_that_already_has_a_winner(): void
    {
        ['competition' => $competition, 'winningUser' => $user, 'winningTicket' => $ticket, 'draw' => $draw, 'drawResult' => $drawResult] = $this->createDrawnCompetition();

        $existing = Winner::factory()->create([
            'competition_id' => $competition->id,
            'draw_id' => $draw->id,
            'draw_result_id' => $drawResult->id,
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
        ]);

        $this->artisan('winners:reconcile')->assertSuccessful();

        $this->assertSame(1, Winner::query()->where('competition_id', $competition->id)->count());
        $this->assertDatabaseHas('winners', ['id' => $existing->id]);
    }

    public function test_never_creates_a_duplicate_across_repeated_runs(): void
    {
        $this->createDrawnCompetition();

        $this->artisan('winners:reconcile')->assertSuccessful();
        $this->artisan('winners:reconcile')->assertSuccessful();

        $this->assertDatabaseCount('winners', 1);
    }

    public function test_ignores_competitions_not_drawn(): void
    {
        Competition::factory()->withStatus(CompetitionStatus::Active)->create();

        $this->artisan('winners:reconcile')->assertSuccessful();

        $this->assertDatabaseCount('winners', 0);
    }
}
