<?php

namespace Tests\Feature\Admin;

use App\Enums\CompetitionStatus;
use App\Enums\PrizeDeliveryStatus;
use App\Enums\WinnerStatus;
use App\Filament\Resources\Winners\Pages\ViewWinner;
use App\Models\Admin;
use App\Models\Prize;
use App\Models\PrizeDelivery;
use App\Models\Winner;
use App\Models\WinnerAuditLog;
use App\Services\Winner\PrizeFulfilmentService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Feature\Winner\Concerns\CreatesWinnerFixtures;
use Tests\TestCase;

/**
 * P11.10 — the Filament fulfilment actions on the winner view. Each test
 * drives the real page, the real policies and the real P10 services; the
 * assertions are about persisted state and audit rows.
 */
class PrizeFulfilmentAdminTest extends TestCase
{
    use CreatesWinnerFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function claimApproved(int $prizeCount = 1): Winner
    {
        ['competition' => $competition, 'winningUser' => $user, 'winningTicket' => $ticket, 'draw' => $draw, 'drawResult' => $drawResult] = $this->createDrawnCompetition($prizeCount);

        return Winner::factory()->create([
            'competition_id' => $competition->id,
            'draw_id' => $draw->id,
            'draw_result_id' => $drawResult->id,
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'status' => WinnerStatus::ClaimApproved,
        ]);
    }

    private function winnerPage(Winner $winner): Testable
    {
        return Livewire::test(ViewWinner::class, ['record' => $winner->getRouteKey()]);
    }

    // ---- Start fulfilment -------------------------------------------------

    public function test_operations_can_start_fulfilment_for_an_approved_winner(): void
    {
        $admin = Admin::factory()->create();
        $winner = $this->claimApproved(1);
        $this->actingAs($admin, 'admin');

        $this->winnerPage($winner)
            ->assertActionVisible('startFulfilment')
            ->callAction('startFulfilment', data: ['reason' => 'Address confirmed by phone'])
            ->assertHasNoActionErrors()
            ->assertNotified('Fulfilment started');

        $this->assertSame(WinnerStatus::DeliveryPending, $winner->fresh()->status);
        $delivery = PrizeDelivery::query()->where('winner_id', $winner->id)->sole();
        $this->assertSame(PrizeDeliveryStatus::Pending, $delivery->status);

        $created = WinnerAuditLog::query()->where('winner_id', $winner->id)->where('event', 'delivery_created')->sole();
        $this->assertSame($admin->id, $created->actor_id);
    }

    public function test_a_multi_prize_competition_requires_the_prize_to_be_chosen_in_the_form(): void
    {
        $admin = Admin::factory()->create();
        $winner = $this->claimApproved(3);
        $this->actingAs($admin, 'admin');

        $this->winnerPage($winner)
            ->callAction('startFulfilment', data: ['reason' => 'Trying without a prize'])
            ->assertHasActionErrors(['prize_id']);

        $this->assertSame(WinnerStatus::ClaimApproved, $winner->fresh()->status);
        $this->assertSame(0, PrizeDelivery::query()->count());
    }

    public function test_a_prize_from_another_competition_cannot_be_submitted_through_the_form(): void
    {
        $admin = Admin::factory()->create();
        $winner = $this->claimApproved(2);
        $foreign = Prize::factory()->create();
        $this->actingAs($admin, 'admin');

        $this->winnerPage($winner)
            ->callAction('startFulfilment', data: ['prize_id' => $foreign->id, 'reason' => 'Crafted request'])
            ->assertHasActionErrors(['prize_id']);

        $this->assertSame(WinnerStatus::ClaimApproved, $winner->fresh()->status);
        $this->assertSame(0, PrizeDelivery::query()->count());
    }

    public function test_operations_can_assign_one_of_several_prizes_through_the_form(): void
    {
        $admin = Admin::factory()->create();
        $winner = $this->claimApproved(2);
        $chosen = app(PrizeFulfilmentService::class)->assignablePrizes($winner->competition)->first();
        $this->actingAs($admin, 'admin');

        $this->winnerPage($winner)
            ->callAction('startFulfilment', data: ['prize_id' => $chosen->id, 'reason' => 'Chosen per draw sheet'])
            ->assertHasNoActionErrors();

        $this->assertSame($chosen->id, PrizeDelivery::query()->where('winner_id', $winner->id)->sole()->prize_id);
        $created = WinnerAuditLog::query()->where('winner_id', $winner->id)->where('event', 'delivery_created')->sole();
        $this->assertSame('admin_assigned', $created->metadata['selection']);
    }

    public function test_a_stale_start_is_reported_without_changing_anything(): void
    {
        $admin = Admin::factory()->create();
        $winner = $this->claimApproved(1);
        $this->actingAs($admin, 'admin');

        // Another admin started fulfilment after this page was loaded.
        $page = $this->winnerPage($winner);
        app(PrizeFulfilmentService::class)->startFulfilment($winner, Admin::factory()->create()->id, 'elsewhere');
        $before = PrizeDelivery::query()->count();

        // Filament re-evaluates visibility against the current winner, so the stale
        // click is a no-op: no duplicate delivery, and the button is gone.
        $page->callAction('startFulfilment', data: ['reason' => 'Stale click'])
            ->assertActionHidden('startFulfilment');

        $this->assertSame($before, PrizeDelivery::query()->count());
        $this->assertSame(WinnerStatus::DeliveryPending, $winner->fresh()->status);
    }

    // ---- Update delivery ---------------------------------------------------

    public function test_operations_can_move_a_delivery_forward_and_the_actor_is_recorded(): void
    {
        $admin = Admin::factory()->create();
        $winner = $this->claimApproved(1);
        app(PrizeFulfilmentService::class)->startFulfilment($winner, $admin->id, 'start');
        $this->actingAs($admin, 'admin');

        $this->winnerPage($winner->fresh())
            ->assertActionVisible('advanceDelivery')
            ->callAction('advanceDelivery', data: [
                'to_status' => PrizeDeliveryStatus::Processing->value,
                'reason' => 'Packing the prize',
                'delivery_method' => 'Tracked courier',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Delivery updated');

        $delivery = PrizeDelivery::query()->where('winner_id', $winner->id)->sole();
        $this->assertSame(PrizeDeliveryStatus::Processing, $delivery->status);
        $this->assertSame('Tracked courier', $delivery->delivery_method);

        $event = WinnerAuditLog::query()->where('winner_id', $winner->id)->where('event', 'delivery_status_changed')->sole();
        $this->assertSame($admin->id, $event->actor_id);
        $this->assertSame('Packing the prize', $event->metadata['reason']);
    }

    public function test_only_legal_next_statuses_are_offered(): void
    {
        $admin = Admin::factory()->create();
        $winner = $this->claimApproved(1);
        app(PrizeFulfilmentService::class)->startFulfilment($winner, $admin->id, 'start');
        $this->actingAs($admin, 'admin');

        $this->winnerPage($winner->fresh())
            ->callAction('advanceDelivery', data: [
                'to_status' => PrizeDeliveryStatus::Delivered->value,
                'reason' => 'Skipping steps',
            ])
            ->assertHasActionErrors(['to_status']);

        $this->assertSame(PrizeDeliveryStatus::Pending, PrizeDelivery::query()->where('winner_id', $winner->id)->sole()->status);
    }

    public function test_marking_delivered_completes_the_winner_and_competition_through_the_service(): void
    {
        $admin = Admin::factory()->create();
        $winner = $this->claimApproved(1);
        $service = app(PrizeFulfilmentService::class);
        $delivery = $service->startFulfilment($winner, $admin->id, 'start');
        $delivery = $service->advance($delivery, PrizeDeliveryStatus::Processing, 'packing', actorId: $admin->id);
        $service->advance($delivery, PrizeDeliveryStatus::Shipped, 'dispatched', actorId: $admin->id);
        $this->actingAs($admin, 'admin');

        $this->winnerPage($winner->fresh())
            ->callAction('advanceDelivery', data: [
                'to_status' => PrizeDeliveryStatus::Delivered->value,
                'reason' => 'Winner confirmed receipt',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(WinnerStatus::Completed, $winner->fresh()->status);
        $this->assertSame(CompetitionStatus::Completed, $winner->competition->fresh()->status);
    }

    public function test_a_stale_delivery_move_is_refused_and_reported(): void
    {
        $admin = Admin::factory()->create();
        $winner = $this->claimApproved(1);
        app(PrizeFulfilmentService::class)->startFulfilment($winner, $admin->id, 'start');
        $this->actingAs($admin, 'admin');

        $page = $this->winnerPage($winner->fresh());
        // Someone else moves the delivery while this page is open.
        $delivery = PrizeDelivery::query()->where('winner_id', $winner->id)->sole();
        app(PrizeFulfilmentService::class)->advance($delivery, PrizeDeliveryStatus::Processing, 'other admin', actorId: $admin->id);

        // The stale choice is no longer among the delivery's legal next steps, so
        // the form rejects it with the reload message — nothing is changed.
        $page->callAction('advanceDelivery', data: [
            'to_status' => PrizeDeliveryStatus::Processing->value,
            'reason' => 'Stale request',
        ])->assertHasActionErrors(['to_status']);

        $this->assertSame(PrizeDeliveryStatus::Processing, $delivery->fresh()->status);
    }

    // ---- Visibility and authorization -------------------------------------

    public function test_inactive_admin_gets_no_fulfilment_actions_and_cannot_load_the_page(): void
    {
        $winner = $this->claimApproved(1);
        $this->actingAs(Admin::factory()->inactive()->create(), 'admin');

        $this->get("/admin/winners/{$winner->id}")->assertForbidden();
        $this->assertSame(WinnerStatus::ClaimApproved, $winner->fresh()->status);
    }

    public function test_the_fulfilment_section_and_actions_render_for_operations(): void
    {
        $admin = Admin::factory()->create();
        $winner = $this->claimApproved(1);
        $this->actingAs($admin, 'admin');

        $this->winnerPage($winner)
            ->assertSee('Fulfilment')
            ->assertSee('No delivery yet')
            ->assertActionVisible('startFulfilment')
            ->assertActionHidden('advanceDelivery');
    }
}
