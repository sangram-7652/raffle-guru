<?php

namespace Tests\Feature\Winner;

use App\Enums\CompetitionStatus;
use App\Enums\OrderStatus;
use App\Enums\WinnerStatus;
use App\Models\Competition;
use App\Models\User;
use App\Models\Winner;
use App\Services\Winner\WinnerException;
use App\Services\Winner\WinnerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Winner\Concerns\CreatesWinnerFixtures;
use Tests\TestCase;

class WinnerCreationTest extends TestCase
{
    use CreatesWinnerFixtures, RefreshDatabase;

    public function test_creates_winner_from_draw_result(): void
    {
        ['competition' => $competition, 'winningUser' => $user, 'winningTicket' => $ticket, 'draw' => $draw, 'drawResult' => $drawResult] = $this->createDrawnCompetition();

        $winner = app(WinnerService::class)->createFromDrawResult($competition);

        $this->assertDatabaseHas('winners', [
            'id' => $winner->id,
            'competition_id' => $competition->id,
            'draw_id' => $draw->id,
            'draw_result_id' => $drawResult->id,
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'status' => WinnerStatus::PendingContact->value,
        ]);

        $this->assertDatabaseHas('winner_audit_logs', [
            'winner_id' => $winner->id,
            'event' => 'winner_created',
            'actor_type' => 'system',
        ]);
    }

    public function test_creating_winner_twice_is_idempotent(): void
    {
        ['competition' => $competition] = $this->createDrawnCompetition();

        $service = app(WinnerService::class);
        $first = $service->createFromDrawResult($competition);
        $second = $service->createFromDrawResult($competition->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Winner::query()->where('competition_id', $competition->id)->count());
    }

    public function test_refuses_to_create_winner_when_competition_not_drawn(): void
    {
        ['competition' => $competition] = $this->createDrawnCompetition();
        $competition->update(['status' => CompetitionStatus::Closed]);

        $this->expectException(WinnerException::class);
        app(WinnerService::class)->createFromDrawResult($competition->fresh());

        $this->assertDatabaseCount('winners', 0);
    }

    public function test_refuses_to_create_winner_when_draw_result_missing(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Drawn)->create();

        $this->expectException(WinnerException::class);
        app(WinnerService::class)->createFromDrawResult($competition);

        $this->assertDatabaseCount('winners', 0);
    }

    public function test_refuses_to_create_winner_when_winning_order_no_longer_paid(): void
    {
        ['competition' => $competition, 'winningTicket' => $ticket] = $this->createDrawnCompetition();
        $ticket->order->update(['status' => OrderStatus::Refunded]);

        $this->expectException(WinnerException::class);
        app(WinnerService::class)->createFromDrawResult($competition->fresh());

        $this->assertDatabaseCount('winners', 0);
    }

    public function test_never_accepts_winner_identity_from_a_second_source(): void
    {
        // The service signature itself only accepts a Competition — there
        // is no code path to pass a ticket_id/user_id override. This test
        // documents/asserts that resolution always matches ticket.user_id,
        // even when a different user exists who might otherwise be
        // confused for the winner.
        ['competition' => $competition, 'winningUser' => $winningUser] = $this->createDrawnCompetition();
        $otherUser = User::factory()->create();

        $winner = app(WinnerService::class)->createFromDrawResult($competition);

        $this->assertSame($winningUser->id, $winner->user_id);
        $this->assertNotSame($otherUser->id, $winner->user_id);
    }
}
