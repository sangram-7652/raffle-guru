<?php

namespace Tests\Feature\Winner;

use App\Enums\PrizeDeliveryStatus;
use App\Enums\WinnerStatus;
use App\Models\Admin;
use App\Models\Winner;
use App\Services\Winner\PrizeFulfilmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Winner\Concerns\CreatesWinnerFixtures;
use Tests\TestCase;

/**
 * P11.10 — what the winner sees while fulfilment moves, and what must never
 * reach them: internal notes, audit reasons, admin identity and metadata.
 */
class FulfilmentCustomerVisibilityTest extends TestCase
{
    use CreatesWinnerFixtures, RefreshDatabase;

    public function test_customer_sees_progress_but_never_admin_notes_reasons_or_admin_ids(): void
    {
        $admin = Admin::factory()->create();
        ['competition' => $competition, 'winningUser' => $user, 'winningTicket' => $ticket, 'draw' => $draw, 'drawResult' => $drawResult] = $this->createDrawnCompetition(1);
        $winner = Winner::factory()->create([
            'competition_id' => $competition->id,
            'draw_id' => $draw->id,
            'draw_result_id' => $drawResult->id,
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'status' => WinnerStatus::ClaimApproved,
        ]);

        $service = app(PrizeFulfilmentService::class);
        $delivery = $service->startFulfilment($winner, $admin->id, 'SECRET-INTERNAL-REASON');
        $delivery = $service->advance($delivery, PrizeDeliveryStatus::Processing, 'SECRET-PACKING-NOTE', actorId: $admin->id);
        $service->advance($delivery, PrizeDeliveryStatus::Shipped, 'SECRET-DISPATCH-NOTE', trackingReference: 'TRACK-9', deliveryMethod: 'Tracked courier', actorId: $admin->id);

        $response = $this->actingAs($user)->getJson('/api/v1/account/winner');

        $response->assertOk()
            ->assertJsonPath('data.delivery.status', 'shipped')
            ->assertJsonPath('data.delivery.tracking_reference', 'TRACK-9')
            ->assertJsonPath('data.delivery.delivery_method', 'Tracked courier');

        $body = $response->getContent();
        $this->assertStringNotContainsString('SECRET', $body);
        $this->assertStringNotContainsString('actor', $body);
        $this->assertStringNotContainsString('metadata', $body);
        $this->assertStringNotContainsString('"notes"', $body);
        $this->assertStringNotContainsString('"prize_id"', $body);
        $this->assertStringNotContainsString('Admin', $body);
    }

    public function test_customer_claim_state_is_unchanged_by_admin_fulfilment(): void
    {
        $admin = Admin::factory()->create();
        ['competition' => $competition, 'winningUser' => $user, 'winningTicket' => $ticket, 'draw' => $draw, 'drawResult' => $drawResult] = $this->createDrawnCompetition(1);
        $winner = Winner::factory()->create([
            'competition_id' => $competition->id,
            'draw_id' => $draw->id,
            'draw_result_id' => $drawResult->id,
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'status' => WinnerStatus::ClaimApproved,
        ]);

        app(PrizeFulfilmentService::class)->startFulfilment($winner, $admin->id, 'start');

        $this->actingAs($user)->getJson('/api/v1/account/winner')
            ->assertOk()
            ->assertJsonPath('data.status', 'delivery_pending')
            ->assertJsonPath('data.delivery.status', 'pending');
    }
}
