<?php

namespace Tests\Feature\Admin;

use App\Enums\WinnerClaimStatus;
use App\Enums\WinnerStatus;
use App\Filament\Resources\Winners\Pages\ListWinners;
use App\Filament\Resources\Winners\Pages\ViewWinner;
use App\Filament\Resources\Winners\WinnerResource;
use App\Models\Admin;
use App\Models\Competition;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Winner;
use App\Models\WinnerAuditLog;
use App\Policies\WinnerPolicy;
use App\Services\Winner\WinnerClaimService;
use App\Services\Winner\WinnerLifecycleService;
use App\Services\Winner\WinnerService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Feature\Winner\Concerns\CreatesWinnerFixtures;
use Tests\TestCase;
use Throwable;

/**
 * P11.9 — WinnerResource: read-only winners plus the one operator
 * lifecycle action P10 leaves to an operator (pending_contact ->
 * contacted), through the REAL WinnerLifecycleService.
 */
class WinnerResourceTest extends TestCase
{
    use CreatesWinnerFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /**
     * A winner exactly as P10 creates it: from a real draw result.
     */
    private function winner(): Winner
    {
        ['competition' => $competition] = $this->createDrawnCompetition();

        return app(WinnerService::class)->createFromDrawResult($competition);
    }

    // ---- Access -----------------------------------------------------

    public function test_guest_is_redirected_to_admin_login(): void
    {
        $winner = $this->winner();

        $this->get('/admin/winners')->assertRedirect('/admin/login');
        $this->get("/admin/winners/{$winner->id}")->assertRedirect('/admin/login');
    }

    public function test_customer_session_is_not_an_admin_session(): void
    {
        $winner = $this->winner();

        $this->actingAs($winner->user, 'web')->get('/admin/winners')->assertRedirect('/admin/login');
        $this->actingAs($winner->user, 'web')->get("/admin/winners/{$winner->id}")->assertRedirect('/admin/login');
    }

    public function test_inactive_admin_is_denied(): void
    {
        $winner = $this->winner();
        $this->actingAs(Admin::factory()->inactive()->create(), 'admin');

        $this->get('/admin/winners')->assertForbidden();
        $this->get("/admin/winners/{$winner->id}")->assertForbidden();
    }

    public function test_operations_and_super_admin_can_list_and_view(): void
    {
        $winner = $this->winner();

        foreach ([Admin::factory()->create(), Admin::factory()->superAdmin()->create()] as $admin) {
            $this->actingAs($admin, 'admin');

            $this->get('/admin/winners')->assertOk()->assertSee($winner->competition->title);
            $this->get("/admin/winners/{$winner->id}")->assertOk()->assertSee($winner->user->email);
        }
    }

    public function test_admin_denied_by_winner_policy_gets_403(): void
    {
        Gate::policy(Winner::class, DenyWinnerViewPolicy::class);
        $winner = $this->winner();
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        $this->get('/admin/winners')->assertForbidden();
        $this->get("/admin/winners/{$winner->id}")->assertForbidden();
    }

    // ---- Read-only enforcement --------------------------------------

    public function test_no_create_edit_delete_routes_capabilities_or_bulk_actions(): void
    {
        $winner = $this->winner();
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        $this->assertSame(['index', 'view'], array_keys(WinnerResource::getPages()));
        $this->get('/admin/winners/create')->assertNotFound();
        $this->get("/admin/winners/{$winner->id}/edit")->assertNotFound();

        $this->assertFalse(WinnerResource::canCreate());
        foreach (['canEdit', 'canDelete', 'canForceDelete', 'canRestore', 'canReplicate'] as $method) {
            $this->assertFalse(WinnerResource::{$method}($winner), $method);
        }

        $table = Livewire::test(ListWinners::class)->instance()->getTable();
        $this->assertSame(['view'], array_keys($table->getFlatActions()));
        $this->assertSame([], $table->getBulkActions());
    }

    /**
     * P11.9 §35 — forged mutations of winner identity/state.
     */
    public function test_crafted_livewire_requests_cannot_mutate_the_winner(): void
    {
        $winner = $this->winner();
        $otherUser = User::factory()->create();
        $otherTicket = Ticket::factory()->create();
        $otherCompetition = Competition::factory()->create();
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');
        $before = DB::table('winners')->where('id', $winner->id)->first();

        $fields = [
            'status' => WinnerStatus::Completed->value,
            'ticket_id' => $otherTicket->id,
            'user_id' => $otherUser->id,
            'competition_id' => $otherCompetition->id,
            'draw_id' => 999,
            'draw_result_id' => 999,
        ];

        $attempts = [];
        foreach ($fields as $field => $value) {
            $attempts[] = fn () => Livewire::test(ViewWinner::class, ['record' => $winner->id])->set("record.{$field}", $value);
            $attempts[] = fn () => Livewire::test(ViewWinner::class, ['record' => $winner->id])->set("data.{$field}", $value)->call('save');
        }
        $attempts[] = fn () => Livewire::test(ViewWinner::class, ['record' => $winner->id])->call('mountAction', 'edit')->call('callMountedAction');
        $attempts[] = fn () => Livewire::test(ViewWinner::class, ['record' => $winner->id])->call('mountAction', 'delete')->call('callMountedAction');
        $attempts[] = fn () => Livewire::test(ListWinners::class)->call('mountTableAction', 'delete', (string) $winner->id)->call('callMountedTableAction');
        // The contact action itself cannot carry a forged target status.
        $attempts[] = fn () => Livewire::test(ViewWinner::class, ['record' => $winner->id])
            ->call('mountAction', 'markContacted')
            ->set('mountedActions.0.data.note', 'x')   // too short -> rejected
            ->set('mountedActions.0.data.status', WinnerStatus::Completed->value)
            ->call('callMountedAction');

        foreach ($attempts as $attempt) {
            try {
                $attempt();
            } catch (Throwable) {
                // Refused outright — also a pass; the DB check is what matters.
            }
        }

        $this->assertEquals($before, DB::table('winners')->where('id', $winner->id)->first());
    }

    // ---- Content ------------------------------------------------------

    public function test_view_shows_identity_derived_from_the_draw(): void
    {
        $winner = $this->winner();
        $this->actingAs(Admin::factory()->create(), 'admin');

        $this->get("/admin/winners/{$winner->id}")
            ->assertOk()
            ->assertSee($winner->competition->title)
            ->assertSee($winner->user->name)
            ->assertSee($winner->user->email)
            ->assertSee("/admin/draws/{$winner->draw_id}", false)
            ->assertSee("/admin/tickets/{$winner->ticket_id}", false)
            ->assertSee("/admin/competitions/{$winner->competition_id}", false)
            ->assertSee("/admin/orders/{$winner->ticket->order_id}", false)
            ->assertSee('Pending Contact')
            ->assertSee('No claim submitted yet')
            ->assertSee('Not resolved yet');

        // Identity is exactly the draw's: result ticket -> its owner.
        $this->assertSame($winner->drawResult->ticket_id, $winner->ticket_id);
        $this->assertSame(Ticket::query()->find($winner->ticket_id)->user_id, $winner->user_id);
    }

    public function test_sensitive_fields_are_never_exposed(): void
    {
        $winner = $this->winner();
        DB::table('users')->where('id', $winner->user_id)->update(['remember_token' => 'REMEMBER-TOKEN-SENTINEL']);
        DB::table('winners')->where('id', $winner->id)->update(['metadata' => json_encode(['internal' => 'WINNER-META-SENTINEL'])]);
        $hash = DB::table('users')->where('id', $winner->user_id)->value('password');
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        foreach (['/admin/winners', "/admin/winners/{$winner->id}"] as $url) {
            $this->get($url)->assertOk()
                ->assertDontSee($hash, false)
                ->assertDontSee('REMEMBER-TOKEN-SENTINEL', false)
                ->assertDontSee('WINNER-META-SENTINEL', false);
        }

        $loaded = WinnerResource::getEloquentQuery()->findOrFail($winner->id)->user;
        $this->assertSame(['id', 'name', 'email'], array_keys($loaded->getAttributes()));
    }

    public function test_audit_trail_shows_actor_only_with_audit_view(): void
    {
        $winner = $this->winner();
        $superAdmin = Admin::factory()->superAdmin()->create();
        app(WinnerLifecycleService::class)->transition($winner, WinnerStatus::Contacted, 'phoned', actorType: 'operator', actorId: $superAdmin->id);

        $this->actingAs($superAdmin, 'admin');
        $this->get("/admin/winners/{$winner->id}")->assertSee('Audit trail')->assertSee("Admin #{$superAdmin->id}");

        $this->actingAs(Admin::factory()->create(), 'admin'); // Operations: no audit.view
        $this->get("/admin/winners/{$winner->id}")->assertDontSee('Audit trail')->assertDontSee("Admin #{$superAdmin->id}");
    }

    // ---- Mark contacted (real WinnerLifecycleService) ---------------

    public function test_mark_contacted_uses_the_real_service_with_actor_attribution(): void
    {
        $winner = $this->winner();
        $operations = Admin::factory()->create();
        $this->actingAs($operations, 'admin');

        Livewire::test(ViewWinner::class, ['record' => $winner->id])
            ->callAction('markContacted', data: ['note' => 'Phoned the winner, confirmed details'])
            ->assertHasNoActionErrors()
            ->assertNotified('Winner marked as contacted');

        $winner->refresh();
        $this->assertSame(WinnerStatus::Contacted, $winner->status);
        $this->assertNotNull($winner->contacted_at);

        $log = WinnerAuditLog::query()->where('winner_id', $winner->id)->where('to_status', WinnerStatus::Contacted->value)->sole();
        $this->assertSame('operator', $log->actor_type);
        $this->assertSame($operations->id, $log->actor_id);
        $this->assertSame('Phoned the winner, confirmed details', $log->metadata['reason']);
    }

    public function test_note_is_required(): void
    {
        $winner = $this->winner();
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(ViewWinner::class, ['record' => $winner->id])
            ->callAction('markContacted', data: ['note' => ''])
            ->assertHasActionErrors(['note' => 'required']);

        $this->assertSame(WinnerStatus::PendingContact, $winner->fresh()->status);
    }

    public function test_action_is_only_offered_where_the_service_allows_it(): void
    {
        $winner = $this->winner();
        app(WinnerLifecycleService::class)->transition($winner, WinnerStatus::Contacted, 'x');
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(ViewWinner::class, ['record' => $winner->id])->assertActionHidden('markContacted');

        // No other winner transition is offered from this resource.
        foreach (['verify', 'approve', 'markDelivered', 'complete', 'transition'] as $name) {
            Livewire::test(ViewWinner::class, ['record' => $winner->id])->assertActionDoesNotExist($name);
        }
    }

    public function test_invalid_transition_is_rejected_by_the_real_service(): void
    {
        $winner = $this->winner();
        $this->actingAs(Admin::factory()->create(), 'admin');

        $rawSubmit = fn () => Livewire::test(ViewWinner::class, ['record' => $winner->id])
            ->call('mountAction', 'markContacted')
            ->set('mountedActions.0.data.note', 'Raw request note')
            ->call('callMountedAction');

        // Positive control: the raw path works when the move is valid...
        $rawSubmit();
        $this->assertSame(WinnerStatus::Contacted, $winner->fresh()->status);

        // ...and the identical request is refused once it no longer is.
        $rawSubmit();

        $this->assertSame(WinnerStatus::Contacted, $winner->fresh()->status);
        $this->assertSame(1, WinnerAuditLog::query()->where('winner_id', $winner->id)->where('to_status', 'contacted')->count());
    }

    /**
     * P11.9 §37 — another admin acts between this page loading and
     * submitting; the service (not the stale UI) decides.
     */
    public function test_stale_state_is_not_overwritten(): void
    {
        $winner = $this->winner();
        $this->actingAs(Admin::factory()->create(), 'admin');

        $this->app->instance(WinnerLifecycleService::class, new class extends WinnerLifecycleService
        {
            private bool $raced = false;

            public function transition(Winner $winner, WinnerStatus $to, string $reason, string $actorType = 'system', ?int $actorId = null, ?array $metadata = null): bool
            {
                if (! $this->raced) {
                    $this->raced = true;
                    parent::transition($winner, WinnerStatus::Contacted, 'other admin', 'operator', 999);
                }

                return parent::transition($winner, $to, $reason, $actorType, $actorId, $metadata);
            }
        });

        Livewire::test(ViewWinner::class, ['record' => $winner->id])
            ->callAction('markContacted', data: ['note' => 'Also phoned them'])
            ->assertNotified('Not changed');

        $this->assertSame(
            [999],
            WinnerAuditLog::query()->where('winner_id', $winner->id)->where('to_status', 'contacted')->pluck('actor_id')->all(),
        );
    }

    public function test_admin_without_winner_transition_cannot_invoke_it(): void
    {
        Gate::policy(Winner::class, DenyWinnerTransitionPolicy::class);
        $winner = $this->winner();
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        Livewire::test(ViewWinner::class, ['record' => $winner->id])
            ->assertActionHidden('markContacted')
            ->call('mountAction', 'markContacted')
            ->set('mountedActions.0.data.note', 'Crafted request')
            ->call('callMountedAction');

        $this->assertSame(WinnerStatus::PendingContact, $winner->fresh()->status);
    }

    // ---- Table ------------------------------------------------------

    public function test_filters_and_search(): void
    {
        $contacted = $this->winner();
        app(WinnerLifecycleService::class)->transition($contacted, WinnerStatus::Contacted, 'x');
        app(WinnerClaimService::class)->submit($contacted->fresh(), 'claim');
        $pending = $this->winner();
        DB::table('users')->where('id', $pending->user_id)->update(['email' => 'unique.winner@example.com']);
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(ListWinners::class)
            ->filterTable('status', [WinnerStatus::PendingContact->value])
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$contacted]);

        Livewire::test(ListWinners::class)
            ->filterTable('has_claim', true)
            ->assertCanSeeTableRecords([$contacted])
            ->assertCanNotSeeTableRecords([$pending]);

        Livewire::test(ListWinners::class)
            ->filterTable('competition', ['competition_id' => $pending->competition_id])
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$contacted]);

        Livewire::test(ListWinners::class)
            ->filterTable('draw', ['draw_id' => $pending->draw_id])
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$contacted]);

        foreach (["#{$pending->id}", 'unique.winner', $pending->competition->title] as $term) {
            Livewire::test(ListWinners::class)
                ->searchTable($term)
                ->assertCanSeeTableRecords([$pending])
                ->assertCanNotSeeTableRecords([$contacted]);
        }
    }

    public function test_list_query_count_does_not_grow_with_rows(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');

        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::test(ListWinners::class);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $this->winner();
        $few = $count();
        foreach (range(1, 6) as $i) {
            $w = $this->winner();
            app(WinnerLifecycleService::class)->transition($w, WinnerStatus::Contacted, 'x');
            app(WinnerClaimService::class)->submit($w->fresh(), 'claim');
        }

        $this->assertSame($few, $count(), 'Winner list N+1');
    }

    public function test_view_query_count_does_not_grow_with_audit_rows(): void
    {
        $winner = $this->winner();
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin'); // audit trail visible

        $count = function () use ($winner): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::test(ViewWinner::class, ['record' => $winner->id]);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        app(WinnerLifecycleService::class)->transition($winner, WinnerStatus::Contacted, 'x');
        $few = $count();

        $claim = app(WinnerClaimService::class)->submit($winner->fresh(), 'claim');
        $claim = app(WinnerClaimService::class)->transition($claim, WinnerClaimStatus::VerificationPending, 'x');
        app(WinnerClaimService::class)->transition($claim, WinnerClaimStatus::Verified, 'x');

        $this->assertSame($few, $count(), 'Winner view queries grow with audit rows');
    }

    public function test_navigation_group(): void
    {
        $this->assertSame('Winners', WinnerResource::getNavigationGroup());
    }
}

class DenyWinnerViewPolicy extends WinnerPolicy
{
    public function viewAny(Admin $admin): bool
    {
        return false;
    }

    public function view(Admin $admin, Winner $winner): bool
    {
        return false;
    }
}

class DenyWinnerTransitionPolicy extends WinnerPolicy
{
    public function transition(Admin $admin, Winner $winner): bool
    {
        return false;
    }
}
