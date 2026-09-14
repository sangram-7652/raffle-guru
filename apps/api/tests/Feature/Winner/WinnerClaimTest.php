<?php

namespace Tests\Feature\Winner;

use App\Enums\WinnerClaimStatus;
use App\Enums\WinnerStatus;
use App\Models\Winner;
use App\Services\Winner\WinnerClaimException;
use App\Services\Winner\WinnerClaimService;
use App\Services\Winner\WinnerLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Winner\Concerns\CreatesWinnerFixtures;
use Tests\TestCase;

class WinnerClaimTest extends TestCase
{
    use CreatesWinnerFixtures, RefreshDatabase;

    private function contactedWinner(): Winner
    {
        ['competition' => $competition, 'winningUser' => $user, 'winningTicket' => $ticket, 'draw' => $draw, 'drawResult' => $drawResult] = $this->createDrawnCompetition();

        $winner = Winner::factory()->create([
            'competition_id' => $competition->id,
            'draw_id' => $draw->id,
            'draw_result_id' => $drawResult->id,
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'status' => WinnerStatus::PendingContact,
        ]);

        app(WinnerLifecycleService::class)->transition($winner, WinnerStatus::Contacted, 'test setup');

        return $winner->fresh();
    }

    public function test_submitting_a_claim_moves_winner_to_verification_pending(): void
    {
        $winner = $this->contactedWinner();

        $claim = app(WinnerClaimService::class)->submit($winner, 'please contact me by email');

        $this->assertSame(WinnerClaimStatus::Submitted, $claim->status);
        $this->assertSame(WinnerStatus::VerificationPending, $winner->fresh()->status);
        $this->assertDatabaseHas('winner_audit_logs', [
            'winner_id' => $winner->id,
            'event' => 'claim_submitted',
            'actor_type' => 'customer',
        ]);
    }

    public function test_cannot_submit_before_being_contacted(): void
    {
        ['competition' => $competition, 'winningUser' => $user, 'winningTicket' => $ticket, 'draw' => $draw, 'drawResult' => $drawResult] = $this->createDrawnCompetition();
        $winner = Winner::factory()->create([
            'competition_id' => $competition->id,
            'draw_id' => $draw->id,
            'draw_result_id' => $drawResult->id,
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'status' => WinnerStatus::PendingContact,
        ]);

        $this->expectException(WinnerClaimException::class);
        app(WinnerClaimService::class)->submit($winner, null);
    }

    public function test_cannot_submit_a_second_claim(): void
    {
        $winner = $this->contactedWinner();
        app(WinnerClaimService::class)->submit($winner, null);

        $this->expectException(WinnerClaimException::class);
        app(WinnerClaimService::class)->submit($winner->fresh(), 'trying again');

        $this->assertDatabaseCount('winner_claims', 1);
    }

    public function test_claim_verified_transition_advances_winner_to_verified(): void
    {
        $winner = $this->contactedWinner();
        $service = app(WinnerClaimService::class);
        $claim = $service->submit($winner, null);

        $service->transition($claim, WinnerClaimStatus::VerificationPending, 'review started');
        $verified = $service->transition($claim->fresh(), WinnerClaimStatus::Verified, 'identity confirmed');

        $this->assertSame(WinnerClaimStatus::Verified, $verified->status);
        $this->assertSame(WinnerStatus::Verified, $winner->fresh()->status);
    }

    public function test_claim_approved_transition_advances_winner_to_claim_approved(): void
    {
        $winner = $this->contactedWinner();
        $service = app(WinnerClaimService::class);
        $claim = $service->submit($winner, null);
        $service->transition($claim, WinnerClaimStatus::VerificationPending, 'review started');
        $service->transition($claim->fresh(), WinnerClaimStatus::Verified, 'identity confirmed');

        $approved = $service->transition($claim->fresh(), WinnerClaimStatus::Approved, 'approved');

        $this->assertSame(WinnerClaimStatus::Approved, $approved->status);
        $this->assertSame(WinnerStatus::ClaimApproved, $winner->fresh()->status);
    }

    public function test_rejects_an_arbitrary_claim_status_jump(): void
    {
        $winner = $this->contactedWinner();
        $service = app(WinnerClaimService::class);
        $claim = $service->submit($winner, null);

        $this->expectException(WinnerClaimException::class);
        $service->transition($claim, WinnerClaimStatus::Approved, 'skip ahead');
    }
}
