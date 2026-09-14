<?php

namespace Tests\Feature\Winner;

use App\Enums\WinnerStatus;
use App\Models\Winner;
use App\Services\Winner\WinnerLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Winner\Concerns\CreatesWinnerFixtures;
use Tests\TestCase;

class WinnerLifecycleServiceTest extends TestCase
{
    use CreatesWinnerFixtures, RefreshDatabase;

    private function winner(): Winner
    {
        ['competition' => $competition, 'winningUser' => $user, 'winningTicket' => $ticket, 'draw' => $draw, 'drawResult' => $drawResult] = $this->createDrawnCompetition();

        return Winner::factory()->create([
            'competition_id' => $competition->id,
            'draw_id' => $draw->id,
            'draw_result_id' => $drawResult->id,
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'status' => WinnerStatus::PendingContact,
        ]);
    }

    public function test_walks_the_full_chain_forward(): void
    {
        $winner = $this->winner();
        $service = app(WinnerLifecycleService::class);

        $chain = [
            WinnerStatus::Contacted,
            WinnerStatus::VerificationPending,
            WinnerStatus::Verified,
            WinnerStatus::ClaimApproved,
            WinnerStatus::DeliveryPending,
            WinnerStatus::Delivered,
            WinnerStatus::Completed,
        ];

        foreach ($chain as $to) {
            $this->assertTrue($service->transition($winner, $to, 'test'));
            $winner->refresh();
            $this->assertSame($to, $winner->status);
        }

        $this->assertNotNull($winner->contacted_at);
        $this->assertNotNull($winner->verified_at);
        $this->assertNotNull($winner->claim_approved_at);
        $this->assertNotNull($winner->delivery_pending_at);
        $this->assertNotNull($winner->delivered_at);
        $this->assertNotNull($winner->completed_at);
    }

    public function test_rejects_backwards_transition(): void
    {
        $winner = $this->winner();
        $service = app(WinnerLifecycleService::class);

        $service->transition($winner, WinnerStatus::Contacted, 'test');
        $winner->refresh();

        $this->assertFalse($service->transition($winner, WinnerStatus::PendingContact, 'test'));
        $this->assertSame(WinnerStatus::Contacted, $winner->fresh()->status);
    }

    public function test_rejects_arbitrary_jump(): void
    {
        $winner = $this->winner();
        $service = app(WinnerLifecycleService::class);

        $this->assertFalse($service->transition($winner, WinnerStatus::Completed, 'test'));
        $this->assertSame(WinnerStatus::PendingContact, $winner->fresh()->status);
    }

    public function test_every_transition_is_audited(): void
    {
        $winner = $this->winner();
        app(WinnerLifecycleService::class)->transition($winner, WinnerStatus::Contacted, 'operator contacted winner');

        $this->assertDatabaseHas('winner_audit_logs', [
            'winner_id' => $winner->id,
            'from_status' => 'pending_contact',
            'to_status' => 'contacted',
        ]);
    }
}
