<?php

namespace Tests\Feature\Winner;

use App\Enums\CompetitionStatus;
use App\Enums\PrizeDeliveryStatus;
use App\Enums\WinnerStatus;
use App\Models\Winner;
use App\Services\Winner\PrizeFulfilmentService;
use App\Services\Winner\PrizeResolutionException;
use App\Services\Winner\WinnerException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Winner\Concerns\CreatesWinnerFixtures;
use Tests\TestCase;

class PrizeFulfilmentTest extends TestCase
{
    use CreatesWinnerFixtures, RefreshDatabase;

    /**
     * @param  int  $prizeCount  how many `prizes` rows the competition has —
     *                           1 is the unambiguous, auto-resolvable case.
     */
    private function claimApprovedWinner(int $prizeCount = 1): Winner
    {
        ['competition' => $competition, 'winningUser' => $user, 'winningTicket' => $ticket, 'draw' => $draw, 'drawResult' => $drawResult] = $this->createDrawnCompetition($prizeCount);

        $winner = Winner::factory()->create([
            'competition_id' => $competition->id,
            'draw_id' => $draw->id,
            'draw_result_id' => $drawResult->id,
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'status' => WinnerStatus::ClaimApproved,
        ]);

        return $winner;
    }

    public function test_delivery_created_only_after_delivery_pending(): void
    {
        $winner = $this->claimApprovedWinner();
        $service = app(PrizeFulfilmentService::class);

        $this->expectException(WinnerException::class);
        $service->createDelivery($winner);
    }

    public function test_full_fulfilment_flow_completes_winner_and_competition(): void
    {
        $winner = $this->claimApprovedWinner();
        $service = app(PrizeFulfilmentService::class);

        $service->markDeliveryPending($winner);
        $delivery = $service->createDelivery($winner->fresh());

        $this->assertSame(PrizeDeliveryStatus::Pending, $delivery->status);
        $this->assertNotNull($delivery->prize_id);

        $delivery = $service->advance($delivery, PrizeDeliveryStatus::Processing, 'preparing shipment');
        $delivery = $service->advance($delivery, PrizeDeliveryStatus::Shipped, 'dispatched', trackingReference: 'TRACK-123', deliveryMethod: 'courier');
        $this->assertNotNull($delivery->shipped_at);

        $delivery = $service->advance($delivery, PrizeDeliveryStatus::Delivered, 'confirmed received');
        $this->assertNotNull($delivery->delivered_at);

        $winner->refresh();
        $this->assertSame(WinnerStatus::Completed, $winner->status);
        $this->assertNotNull($winner->completed_at);

        $this->assertSame(CompetitionStatus::Completed, $winner->competition->fresh()->status);
    }

    public function test_ambiguous_prize_count_refuses_to_create_a_delivery(): void
    {
        $winner = $this->claimApprovedWinner(prizeCount: 2);
        $service = app(PrizeFulfilmentService::class);
        $service->markDeliveryPending($winner);

        $this->expectException(PrizeResolutionException::class);
        $service->createDelivery($winner->fresh());

        $this->assertDatabaseCount('prize_deliveries', 0);
        // Winner stays delivery_pending, safe to retry once resolved —
        // never silently guesses a prize.
        $this->assertSame(WinnerStatus::DeliveryPending, $winner->fresh()->status);
    }

    public function test_zero_prizes_refuses_to_create_a_delivery(): void
    {
        $winner = $this->claimApprovedWinner(prizeCount: 0);
        $service = app(PrizeFulfilmentService::class);
        $service->markDeliveryPending($winner);

        $this->expectException(PrizeResolutionException::class);
        $service->createDelivery($winner->fresh());
    }

    public function test_second_delivery_creation_is_idempotent(): void
    {
        $winner = $this->claimApprovedWinner();
        $service = app(PrizeFulfilmentService::class);
        $service->markDeliveryPending($winner);

        $first = $service->createDelivery($winner->fresh());
        $second = $service->createDelivery($winner->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('prize_deliveries', 1);
    }

    public function test_competition_completion_never_happens_immediately_after_draw(): void
    {
        // A freshly-drawn competition, no winner progression at all,
        // must never reach completed on its own.
        ['competition' => $competition] = $this->createDrawnCompetition();

        $this->assertSame(CompetitionStatus::Drawn, $competition->fresh()->status);
    }
}
