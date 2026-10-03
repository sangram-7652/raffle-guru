<?php

namespace Tests\Feature\Admin;

use App\Enums\CompetitionStatus;
use App\Events\CompetitionDrawPending;
use App\Filament\Resources\Competitions\Pages\ViewCompetition;
use App\Models\Admin;
use App\Models\Competition;
use App\Models\CompetitionLifecycleEvent;
use App\Policies\CompetitionPolicy;
use App\Services\Competitions\CompetitionLifecycleService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * P11.5 — the "Transition status" action on the competition view page.
 * Uses the REAL CompetitionLifecycleService throughout (never a mock),
 * so these prove the admin UI is wired to the domain layer: the
 * transition happens, the service writes its own audit event, and the
 * service's matrix/locking stays the final authority.
 */
class CompetitionLifecycleActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function viewPage(Competition $competition): Testable
    {
        return Livewire::test(ViewCompetition::class, ['record' => $competition->getRouteKey()]);
    }

    public function test_valid_transition_goes_through_the_real_service_and_writes_its_audit_event(): void
    {
        $operations = Admin::factory()->create();
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create();
        $this->actingAs($operations, 'admin');

        $this->viewPage($competition)
            ->callAction('transitionStatus', data: [
                'to_status' => CompetitionStatus::Closed->value,
                'reason' => 'Closing early for operational reasons',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Status changed to Closed');

        $this->assertSame(CompetitionStatus::Closed, $competition->fresh()->status);

        $events = CompetitionLifecycleEvent::query()->where('competition_id', $competition->id)->get();
        $this->assertCount(1, $events);
        $this->assertSame(CompetitionStatus::Active->value, $events[0]->from_status);
        $this->assertSame(CompetitionStatus::Closed->value, $events[0]->to_status);
        $this->assertSame("Admin #{$operations->id}: Closing early for operational reasons", $events[0]->reason);
    }

    public function test_super_admin_can_transition(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Scheduled)->create();
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        $this->viewPage($competition)
            ->callAction('transitionStatus', data: [
                'to_status' => CompetitionStatus::Active->value,
                'reason' => 'Launching now',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(CompetitionStatus::Active, $competition->fresh()->status);
    }

    public function test_only_currently_valid_targets_are_offered(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create();

        $this->viewPage($competition)
            ->mountAction('transitionStatus')
            ->assertFormFieldExists('to_status', function ($field): bool {
                return array_keys($field->getOptions()) === [
                    CompetitionStatus::SoldOut->value,
                    CompetitionStatus::Closed->value,
                ];
            });
    }

    public function test_reason_is_required(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create();
        $this->actingAs(Admin::factory()->create(), 'admin');

        $this->viewPage($competition)
            ->callAction('transitionStatus', data: [
                'to_status' => CompetitionStatus::Closed->value,
                'reason' => '',
            ])
            ->assertHasActionErrors(['reason' => 'required']);

        $this->assertSame(CompetitionStatus::Active, $competition->fresh()->status);
        $this->assertSame(0, CompetitionLifecycleEvent::query()->count());
    }

    public function test_draft_offers_only_scheduled_as_its_publish_target(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');
        $competition = Competition::factory()->withStatus(CompetitionStatus::Draft)->create();

        $this->viewPage($competition)
            ->mountAction('transitionStatus')
            ->assertFormFieldExists('to_status', function ($field): bool {
                return array_keys($field->getOptions()) === [CompetitionStatus::Scheduled->value];
            });
    }

    public function test_publishing_a_draft_schedules_it_through_the_service_with_an_audit_event(): void
    {
        $operations = Admin::factory()->create();
        $competition = Competition::factory()->withStatus(CompetitionStatus::Draft)->create();
        $this->actingAs($operations, 'admin');

        $this->viewPage($competition)
            ->callAction('transitionStatus', data: [
                'to_status' => CompetitionStatus::Scheduled->value,
                'reason' => 'Ready for launch',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(CompetitionStatus::Scheduled, $competition->fresh()->status);

        $events = CompetitionLifecycleEvent::query()->where('competition_id', $competition->id)->get();
        $this->assertCount(1, $events);
        $this->assertSame(CompetitionStatus::Draft->value, $events[0]->from_status);
        $this->assertSame(CompetitionStatus::Scheduled->value, $events[0]->to_status);
        $this->assertSame("Admin #{$operations->id}: Ready for launch", $events[0]->reason);
    }

    public function test_a_draft_cannot_be_crafted_straight_to_active(): void
    {
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');
        $competition = Competition::factory()->withStatus(CompetitionStatus::Draft)->create();

        $this->viewPage($competition)
            ->callAction('transitionStatus', data: [
                'to_status' => CompetitionStatus::Active->value,
                'reason' => 'Skipping the schedule',
            ])
            ->assertHasActionErrors(['to_status']);

        $this->assertSame(CompetitionStatus::Draft, $competition->fresh()->status);
        $this->assertSame(0, CompetitionLifecycleEvent::query()->count());
    }

    public function test_action_is_hidden_when_no_manual_transition_exists(): void
    {
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        // draw_pending/draw_in_progress/drawn: only DrawService/
        // PrizeFulfilmentService may move these on.
        // completed: terminal.
        // (draft is not here: P11.9 gave it one manual edge, to scheduled.)
        foreach ([
            CompetitionStatus::DrawPending,
            CompetitionStatus::DrawInProgress,
            CompetitionStatus::Drawn,
            CompetitionStatus::Completed,
        ] as $status) {
            $competition = Competition::factory()->withStatus($status)->create();

            $this->viewPage($competition)->assertActionHidden('transitionStatus');
        }
    }

    /**
     * UI filtering is not the security boundary. The service's matrix
     * DOES allow draw_pending -> draw_in_progress, so a crafted payload
     * must be stopped by the action itself.
     */
    public function test_crafted_draw_state_targets_are_rejected_server_side(): void
    {
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');
        $competition = Competition::factory()->withStatus(CompetitionStatus::Closed)->create();
        Event::fake([CompetitionDrawPending::class]);

        foreach ([CompetitionStatus::DrawInProgress, CompetitionStatus::Drawn, CompetitionStatus::Completed, CompetitionStatus::Active] as $target) {
            $this->viewPage($competition)
                ->mountAction('transitionStatus')
                ->set('mountedActions.0.data.to_status', $target->value)
                ->set('mountedActions.0.data.reason', 'Crafted request')
                ->call('callMountedAction');

            $this->assertSame(CompetitionStatus::Closed, $competition->fresh()->status, "Forged {$target->value}");
        }

        $this->assertSame(0, CompetitionLifecycleEvent::query()->count());

        // Positive control through the identical raw path: a legitimate
        // target does go through, so the rejections above are real.
        $this->viewPage($competition)
            ->mountAction('transitionStatus')
            ->set('mountedActions.0.data.to_status', CompetitionStatus::DrawPending->value)
            ->set('mountedActions.0.data.reason', 'Legitimate request')
            ->call('callMountedAction');

        $this->assertSame(CompetitionStatus::DrawPending, $competition->fresh()->status);
    }

    public function test_invalid_transition_remains_invalid_in_the_real_service(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Closed)->create();

        $this->assertFalse(app(CompetitionLifecycleService::class)->transition($competition, CompetitionStatus::Active, 'backwards'));
        $this->assertSame(CompetitionStatus::Closed, $competition->fresh()->status);
        $this->assertSame(0, CompetitionLifecycleEvent::query()->count());
    }

    public function test_closed_to_draw_pending_hands_off_to_the_draw_engine(): void
    {
        Event::fake([CompetitionDrawPending::class]);
        $competition = Competition::factory()->withStatus(CompetitionStatus::Closed)->create();
        $this->actingAs(Admin::factory()->create(), 'admin');

        $this->viewPage($competition)
            ->callAction('transitionStatus', data: [
                'to_status' => CompetitionStatus::DrawPending->value,
                'reason' => 'Drawing early — all entries verified',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(CompetitionStatus::DrawPending, $competition->fresh()->status);
        Event::assertDispatched(CompetitionDrawPending::class);
    }

    public function test_admin_denied_by_policy_cannot_see_or_invoke_the_action(): void
    {
        // Both current roles hold competition.lifecycle, so deny it via
        // the policy itself — proving the action really consults
        // CompetitionPolicy::transitionLifecycle rather than a role check.
        Gate::policy(Competition::class, DenyLifecyclePolicy::class);

        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create();
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        $this->viewPage($competition)
            ->assertActionHidden('transitionStatus')
            ->call('mountAction', 'transitionStatus')
            ->set('mountedActions.0.data.to_status', CompetitionStatus::Closed->value)
            ->set('mountedActions.0.data.reason', 'Crafted request')
            ->call('callMountedAction');

        $this->assertSame(CompetitionStatus::Active, $competition->fresh()->status);
        $this->assertSame(0, CompetitionLifecycleEvent::query()->count());
    }

    public function test_inactive_admin_fails_the_lifecycle_policy(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create();

        $this->assertFalse(Admin::factory()->inactive()->create()->can('transitionLifecycle', $competition));
    }

    /**
     * P11.5 §13 — the real race. The action validates active -> sold_out
     * against what it can see, then, between that check and the
     * service's row lock, another process (the scheduler) closes the
     * competition. The service re-reads the locked row, finds `closed`,
     * and refuses: status is never blindly overwritten, and the admin is
     * told the truth.
     */
    public function test_status_changed_before_the_service_locks_the_row_is_not_overwritten(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create();
        $this->actingAs(Admin::factory()->create(), 'admin');

        $this->app->instance(CompetitionLifecycleService::class, new class extends CompetitionLifecycleService
        {
            private bool $raced = false;

            public function transition(Competition $competition, CompetitionStatus $to, string $reason): bool
            {
                if (! $this->raced && str_starts_with($reason, 'Admin #')) {
                    $this->raced = true;
                    (new CompetitionLifecycleService)->transition($competition, CompetitionStatus::Closed, 'ends_at reached');
                }

                return parent::transition($competition, $to, $reason);
            }
        });

        $this->viewPage($competition)
            ->callAction('transitionStatus', data: [
                'to_status' => CompetitionStatus::SoldOut->value,
                'reason' => 'Marking sold out',
            ])
            ->assertNotified('Transition rejected');

        $this->assertSame(CompetitionStatus::Closed, $competition->fresh()->status);
        $this->assertSame(
            [CompetitionStatus::Closed->value],
            CompetitionLifecycleEvent::query()->where('competition_id', $competition->id)->pluck('to_status')->all(),
        );
    }

    public function test_service_ignores_a_stale_in_memory_status(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create();
        $stale = Competition::query()->findOrFail($competition->id);

        app(CompetitionLifecycleService::class)->transition($competition, CompetitionStatus::Closed, 'scheduler');

        // $stale still says active; active -> sold_out would be valid
        // from there, but not from the real (closed) row.
        $this->assertSame(CompetitionStatus::Active, $stale->status);
        $this->assertFalse(app(CompetitionLifecycleService::class)->transition($stale, CompetitionStatus::SoldOut, 'stale'));
        $this->assertSame(CompetitionStatus::Closed, $competition->fresh()->status);
    }
}

class DenyLifecyclePolicy extends CompetitionPolicy
{
    public function transitionLifecycle(Admin $admin, Competition $competition): bool
    {
        return false;
    }
}
