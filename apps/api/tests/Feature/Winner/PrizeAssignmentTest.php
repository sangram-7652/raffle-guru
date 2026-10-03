<?php

namespace Tests\Feature\Winner;

use App\Enums\CompetitionStatus;
use App\Enums\PrizeDeliveryStatus;
use App\Enums\WinnerStatus;
use App\Models\Admin;
use App\Models\Prize;
use App\Models\PrizeDelivery;
use App\Models\Winner;
use App\Models\WinnerAuditLog;
use App\Services\Winner\PrizeFulfilmentService;
use App\Services\Winner\PrizeResolutionException;
use App\Services\Winner\WinnerException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Winner\Concerns\CreatesWinnerFixtures;
use Tests\TestCase;

/**
 * P11.10 — admin fulfilment through the REAL P10 services. Nothing here
 * mocks a domain service: each assertion is about what the domain
 * persisted (statuses, delivery rows, audit actor ids), not about calls.
 */
class PrizeAssignmentTest extends TestCase
{
    use CreatesWinnerFixtures, RefreshDatabase;

    /** Competition with $prizeCount prizes and an approved winner. @return array{winner: Winner, competition: \App\Models\Competition} */
    private function claimApproved(int $prizeCount = 1): array
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

        return ['winner' => $winner, 'competition' => $competition];
    }

    private function service(): PrizeFulfilmentService
    {
        return app(PrizeFulfilmentService::class);
    }

    // ---- Single-prize competitions: resolved automatically ---------------

    public function test_single_prize_competition_starts_without_any_manual_choice(): void
    {
        $admin = Admin::factory()->create();
        ['winner' => $winner] = $this->claimApproved(1);

        $delivery = $this->service()->startFulfilment($winner, $admin->id, 'starting fulfilment');

        $this->assertSame(PrizeDeliveryStatus::Pending, $delivery->status);
        $this->assertSame(WinnerStatus::DeliveryPending, $winner->fresh()->status);
        $this->assertSame(Prize::query()->where('competition_id', $winner->competition_id)->sole()->id, $delivery->prize_id);
    }

    public function test_single_prize_start_is_recorded_as_resolved_not_admin_assigned(): void
    {
        $admin = Admin::factory()->create();
        ['winner' => $winner] = $this->claimApproved(1);

        $this->service()->startFulfilment($winner, $admin->id, 'starting fulfilment');

        $created = WinnerAuditLog::query()->where('winner_id', $winner->id)->where('event', 'delivery_created')->sole();
        $this->assertSame('resolved', $created->metadata['selection']);
        $this->assertSame($admin->id, $created->actor_id);
    }

    // ---- Multi-prize competitions: a human must choose, never a guess -----

    public function test_multi_prize_competition_refuses_to_guess_and_leaves_no_partial_state(): void
    {
        $admin = Admin::factory()->create();
        ['winner' => $winner] = $this->claimApproved(3);

        $this->assertTrue($this->service()->prizeSelectionRequired($winner->competition));

        try {
            $this->service()->startFulfilment($winner, $admin->id, 'starting fulfilment');
            $this->fail('An ambiguous multi-prize competition must not be resolved silently.');
        } catch (PrizeResolutionException) {
            // expected
        }

        // The whole operation rolled back: no delivery, and the winner did
        // not advance to delivery_pending without one.
        $this->assertSame(WinnerStatus::ClaimApproved, $winner->fresh()->status);
        $this->assertSame(0, PrizeDelivery::query()->where('winner_id', $winner->id)->count());
    }

    public function test_admin_can_assign_one_of_several_prizes_and_the_choice_is_audited(): void
    {
        $admin = Admin::factory()->create();
        ['winner' => $winner, 'competition' => $competition] = $this->claimApproved(3);
        $chosen = Prize::query()->where('competition_id', $competition->id)->orderBy('id')->get()->last();

        $delivery = $this->service()->startFulfilment($winner, $admin->id, 'Top prize per draw sheet', $chosen);

        $this->assertSame($chosen->id, $delivery->prize_id);
        $created = WinnerAuditLog::query()->where('winner_id', $winner->id)->where('event', 'delivery_created')->sole();
        $this->assertSame('admin_assigned', $created->metadata['selection']);
        $this->assertSame($admin->id, $created->actor_id);
    }

    public function test_a_prize_from_another_competition_is_refused_and_nothing_changes(): void
    {
        $admin = Admin::factory()->create();
        ['winner' => $winner] = $this->claimApproved(1);
        $foreign = Prize::factory()->create();

        try {
            $this->service()->startFulfilment($winner, $admin->id, 'wrong prize', $foreign);
            $this->fail('A prize from another competition must never be assigned.');
        } catch (WinnerException $e) {
            $this->assertStringContainsString('does not belong', $e->getMessage());
        }

        $this->assertSame(WinnerStatus::ClaimApproved, $winner->fresh()->status);
        $this->assertSame(0, PrizeDelivery::query()->count());
    }

    public function test_assignable_prizes_only_lists_this_competitions_prizes_with_stock_left(): void
    {
        ['winner' => $winner, 'competition' => $competition] = $this->claimApproved(2);
        $prizes = Prize::query()->where('competition_id', $competition->id)->get();
        $foreign = Prize::factory()->create();

        $ids = $this->service()->assignablePrizes($competition)->pluck('id')->all();

        $this->assertEqualsCanonicalizing($prizes->pluck('id')->all(), $ids);
        $this->assertNotContains($foreign->id, $ids);
    }

    public function test_a_prize_whose_only_unit_is_already_delivered_is_no_longer_assignable(): void
    {
        $admin = Admin::factory()->create();
        ['winner' => $winner, 'competition' => $competition] = $this->claimApproved(2);
        $chosen = $this->service()->assignablePrizes($competition)->first();

        $this->service()->startFulfilment($winner, $admin->id, 'assign', $chosen);

        $this->assertNotContains($chosen->id, $this->service()->assignablePrizes($competition)->pluck('id')->all());
    }

    // ---- Existing deliveries are never silently reassigned ---------------

    public function test_an_existing_delivery_cannot_be_reassigned_to_a_different_prize(): void
    {
        $admin = Admin::factory()->create();
        ['winner' => $winner, 'competition' => $competition] = $this->claimApproved(2);
        [$first, $second] = Prize::query()->where('competition_id', $competition->id)->orderBy('id')->get();

        $this->service()->startFulfilment($winner, $admin->id, 'assign first', $first);

        try {
            $this->service()->createDelivery($winner->fresh(), $second, $admin->id);
            $this->fail('An existing delivery must not be reassigned to a different prize.');
        } catch (WinnerException $e) {
            $this->assertStringContainsString('cannot be reassigned', $e->getMessage());
        }

        $this->assertSame($first->id, PrizeDelivery::query()->where('winner_id', $winner->id)->sole()->prize_id);
    }

    public function test_repeating_the_same_assignment_returns_the_existing_delivery(): void
    {
        $admin = Admin::factory()->create();
        ['winner' => $winner, 'competition' => $competition] = $this->claimApproved(2);
        $chosen = Prize::query()->where('competition_id', $competition->id)->orderBy('id')->first();

        $first = $this->service()->startFulfilment($winner, $admin->id, 'assign', $chosen);
        $again = $this->service()->createDelivery($winner->fresh(), $chosen, $admin->id);

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, PrizeDelivery::query()->where('winner_id', $winner->id)->count());
    }

    // ---- Delivery lifecycle, using the real matrix -----------------------

    public function test_only_matrix_legal_delivery_moves_succeed(): void
    {
        $admin = Admin::factory()->create();
        ['winner' => $winner] = $this->claimApproved(1);
        $delivery = $this->service()->startFulfilment($winner, $admin->id, 'start');

        $this->assertSame([PrizeDeliveryStatus::Processing, PrizeDeliveryStatus::Failed], $this->service()->allowedNextStatuses($delivery->status));
        $this->assertFalse($this->service()->canTransition(PrizeDeliveryStatus::Pending, PrizeDeliveryStatus::Delivered));
        $this->assertTrue($this->service()->canTransition(PrizeDeliveryStatus::Shipped, PrizeDeliveryStatus::Delivered));
    }

    public function test_an_invalid_delivery_jump_is_rejected_and_nothing_moves(): void
    {
        $admin = Admin::factory()->create();
        ['winner' => $winner] = $this->claimApproved(1);
        $delivery = $this->service()->startFulfilment($winner, $admin->id, 'start');

        try {
            $this->service()->advance($delivery, PrizeDeliveryStatus::Delivered, 'skipping ahead', actorId: $admin->id);
            $this->fail('pending -> delivered must be rejected.');
        } catch (WinnerException) {
            // expected
        }

        $this->assertSame(PrizeDeliveryStatus::Pending, $delivery->fresh()->status);
        $this->assertSame(WinnerStatus::DeliveryPending, $winner->fresh()->status);
        $this->assertSame(CompetitionStatus::Drawn, $winner->competition->fresh()->status);
    }

    public function test_completion_is_refused_before_the_prize_is_delivered(): void
    {
        $admin = Admin::factory()->create();
        ['winner' => $winner, 'competition' => $competition] = $this->claimApproved(1);
        $delivery = $this->service()->startFulfilment($winner, $admin->id, 'start');
        $this->service()->advance($delivery, PrizeDeliveryStatus::Processing, 'packing', actorId: $admin->id);
        $this->service()->advance($delivery->fresh(), PrizeDeliveryStatus::Shipped, 'dispatched', actorId: $admin->id);

        $this->assertSame(WinnerStatus::DeliveryPending, $winner->fresh()->status);
        $this->assertSame(CompetitionStatus::Drawn, $competition->fresh()->status);
    }

    public function test_full_admin_flow_records_actor_on_every_fulfilment_step_and_completes_winner_and_competition(): void
    {
        $admin = Admin::factory()->create();
        ['winner' => $winner, 'competition' => $competition] = $this->claimApproved(1);

        $delivery = $this->service()->startFulfilment($winner, $admin->id, 'start');
        $delivery = $this->service()->advance($delivery, PrizeDeliveryStatus::Processing, 'packing', actorId: $admin->id);
        $delivery = $this->service()->advance($delivery, PrizeDeliveryStatus::Shipped, 'dispatched', trackingReference: 'REF-1', deliveryMethod: 'Tracked courier', actorId: $admin->id);
        $delivery = $this->service()->advance($delivery, PrizeDeliveryStatus::Delivered, 'confirmed received', actorId: $admin->id);

        $this->assertSame('REF-1', $delivery->tracking_reference);
        $this->assertSame(WinnerStatus::Completed, $winner->fresh()->status);
        $this->assertSame(CompetitionStatus::Completed, $competition->fresh()->status);

        $adminEvents = WinnerAuditLog::query()
            ->where('winner_id', $winner->id)
            ->whereIn('event', ['delivery_created', 'delivery_status_changed', 'prize_shipped', 'prize_delivered', 'winner_status_changed'])
            ->get();

        $this->assertNotEmpty($adminEvents);
        foreach ($adminEvents as $event) {
            if ($event->actor_type === 'operator') {
                $this->assertSame($admin->id, $event->actor_id, "event {$event->event} must carry the admin id");
            }
        }
        $this->assertSame($admin->id, $adminEvents->firstWhere('event', 'prize_delivered')->actor_id);
    }

    public function test_internal_reason_is_kept_in_the_audit_trail_for_each_move(): void
    {
        $admin = Admin::factory()->create();
        ['winner' => $winner] = $this->claimApproved(1);
        $delivery = $this->service()->startFulfilment($winner, $admin->id, 'Confirmed address with winner');

        $this->service()->advance($delivery, PrizeDeliveryStatus::Failed, 'Courier refused parcel', actorId: $admin->id);

        $failed = WinnerAuditLog::query()->where('winner_id', $winner->id)->where('event', 'delivery_failed')->sole();
        $this->assertSame('Courier refused parcel', $failed->metadata['reason']);
        $this->assertSame($admin->id, $failed->actor_id);
    }

    public function test_failed_is_terminal_so_no_further_move_is_possible(): void
    {
        $admin = Admin::factory()->create();
        ['winner' => $winner] = $this->claimApproved(1);
        $delivery = $this->service()->startFulfilment($winner, $admin->id, 'start');
        $delivery = $this->service()->advance($delivery, PrizeDeliveryStatus::Failed, 'lost', actorId: $admin->id);

        $this->assertSame([], $this->service()->allowedNextStatuses($delivery->status));
        $this->expectException(WinnerException::class);
        $this->service()->advance($delivery, PrizeDeliveryStatus::Processing, 'retry', actorId: $admin->id);
    }

    // ---- Stale requests and races ---------------------------------------

    public function test_a_stale_second_move_is_rejected_cleanly(): void
    {
        $admin = Admin::factory()->create();
        ['winner' => $winner] = $this->claimApproved(1);
        $delivery = $this->service()->startFulfilment($winner, $admin->id, 'start');

        // Two admins loaded the same page: both want "processing".
        $stale = PrizeDelivery::query()->findOrFail($delivery->id);
        $this->service()->advance($delivery, PrizeDeliveryStatus::Processing, 'first admin', actorId: $admin->id);

        $this->expectException(WinnerException::class);
        $this->service()->advance($stale, PrizeDeliveryStatus::Processing, 'second admin', actorId: $admin->id);
    }

    public function test_repeating_start_fulfilment_is_safe_and_creates_one_delivery(): void
    {
        $admin = Admin::factory()->create();
        ['winner' => $winner] = $this->claimApproved(1);

        $first = $this->service()->startFulfilment($winner, $admin->id, 'start');

        // The second click finds the winner already in delivery_pending.
        $second = $this->service()->startFulfilment($winner->fresh(), $admin->id, 'start again');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, PrizeDelivery::query()->where('winner_id', $winner->id)->count());
    }

    public function test_start_fulfilment_is_refused_for_a_winner_not_yet_approved(): void
    {
        $admin = Admin::factory()->create();
        ['winner' => $winner] = $this->claimApproved(1);
        $winner->update(['status' => WinnerStatus::Verified]);

        $this->expectException(WinnerException::class);
        $this->service()->startFulfilment($winner->fresh(), $admin->id, 'too early');
    }

    public function test_a_delivery_cannot_complete_a_winner_that_is_no_longer_delivery_pending(): void
    {
        // Simulate a winner that somehow reached completed while its delivery
        // was still shipped. Delivering must refuse, leaving the delivery
        // shipped — never a delivered prize with an uncompleted winner.
        $admin = Admin::factory()->create();
        ['winner' => $winner, 'competition' => $competition] = $this->claimApproved(1);
        $delivery = $this->service()->startFulfilment($winner, $admin->id, 'start');
        $delivery = $this->service()->advance($delivery, PrizeDeliveryStatus::Processing, 'packing', actorId: $admin->id);
        $delivery = $this->service()->advance($delivery, PrizeDeliveryStatus::Shipped, 'dispatched', actorId: $admin->id);
        $winner->update(['status' => WinnerStatus::Completed]);

        try {
            $this->service()->advance($delivery, PrizeDeliveryStatus::Delivered, 'received', actorId: $admin->id);
            $this->fail('Delivery must not complete when the winner cannot be marked delivered.');
        } catch (WinnerException) {
            // expected
        }

        $this->assertSame(PrizeDeliveryStatus::Shipped, $delivery->fresh()->status);
        $this->assertSame(CompetitionStatus::Drawn, $competition->fresh()->status);
    }

    public function test_the_prize_resolution_ambiguity_check_still_applies_when_no_prize_is_given(): void
    {
        ['winner' => $winner] = $this->claimApproved(2);
        $winner->update(['status' => WinnerStatus::DeliveryPending]);

        $this->expectException(PrizeResolutionException::class);
        $this->service()->createDelivery($winner->fresh());
    }
}
