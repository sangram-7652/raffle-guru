<?php

namespace Tests\Feature\Admin;

use App\Enums\CompetitionStatus;
use App\Filament\Resources\Competitions\Pages\EditCompetition;
use App\Filament\Resources\Competitions\Pages\ViewCompetition;
use App\Filament\Resources\Competitions\RelationManagers\PrizesRelationManager;
use App\Filament\Resources\Prizes\Pages\CreatePrize;
use App\Filament\Resources\Prizes\Pages\EditPrize;
use App\Filament\Resources\Prizes\Pages\ListPrizes;
use App\Models\Admin;
use App\Models\Competition;
use App\Models\Prize;
use App\Models\User;
use App\Policies\PrizePolicy;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * P11.5 — PrizeResource + the Competition -> Prizes relation manager:
 * CRUD, money, relationship integrity against crafted requests, the
 * pre-launch gate on changing a competition's prize set, and
 * PrizePolicy enforcement.
 */
class PrizeResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function draftCompetition(): Competition
    {
        return Competition::factory()->withStatus(CompetitionStatus::Draft)->create();
    }

    // ---- Access -----------------------------------------------------

    public function test_guest_is_redirected_to_admin_login(): void
    {
        $this->get('/admin/prizes')->assertRedirect('/admin/login');
    }

    public function test_customer_session_is_not_an_admin_session(): void
    {
        $this->actingAs(User::factory()->create(), 'web')
            ->get('/admin/prizes')
            ->assertRedirect('/admin/login');
    }

    public function test_inactive_admin_is_denied(): void
    {
        $this->actingAs(Admin::factory()->inactive()->create(), 'admin')
            ->get('/admin/prizes')
            ->assertForbidden();
    }

    public function test_operations_and_super_admin_can_open_every_prize_page(): void
    {
        $prize = Prize::factory()->create();

        foreach ([Admin::factory()->create(), Admin::factory()->superAdmin()->create()] as $admin) {
            $this->actingAs($admin, 'admin');

            $this->get('/admin/prizes')->assertOk();
            $this->get('/admin/prizes/create')->assertOk();
            $this->get("/admin/prizes/{$prize->id}")->assertOk();
            $this->get("/admin/prizes/{$prize->id}/edit")->assertOk();
        }
    }

    public function test_prize_list_query_count_does_not_grow_with_rows(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');
        Prize::factory()->count(3)->create();

        $countQueries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::test(ListPrizes::class);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $few = $countQueries();
        Prize::factory()->count(20)->create();
        $many = $countQueries();

        $this->assertSame($few, $many, 'Prize list issues per-row queries (N+1).');
    }

    // ---- Create / update --------------------------------------------

    public function test_operations_can_create_a_prize_with_money_in_minor_units(): void
    {
        $competition = $this->draftCompetition();
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(CreatePrize::class)
            ->fillForm([
                'competition_id' => $competition->id,
                'title' => 'Cash Prize',
                'description' => [['point' => 'Paid by bank transfer']],
                'value' => '500.50',
                'cash_alternative_value' => '0.1',
                'quantity' => 2,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $row = DB::table('prizes')->where('title', 'Cash Prize')->first();
        $this->assertSame($competition->id, (int) $row->competition_id);
        $this->assertSame(50050, (int) $row->value);
        $this->assertSame(10, (int) $row->cash_alternative_value);
        $this->assertSame('GBP', $row->currency);
        $this->assertSame(2, (int) $row->quantity);
        $this->assertSame(['Paid by bank transfer'], Prize::query()->where('title', 'Cash Prize')->first()->description);
    }

    public function test_cash_alternative_is_optional_and_sub_penny_values_are_rejected(): void
    {
        $competition = $this->draftCompetition();
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(CreatePrize::class)
            ->fillForm(['competition_id' => $competition->id, 'title' => 'Bad', 'value' => '12.345', 'quantity' => 1])
            ->call('create')
            ->assertHasFormErrors(['value']);

        Livewire::test(CreatePrize::class)
            ->fillForm(['competition_id' => $competition->id, 'title' => 'No cash alt', 'value' => '100', 'quantity' => 1])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertNull(DB::table('prizes')->where('title', 'No cash alt')->value('cash_alternative_value'));
    }

    public function test_operations_can_update_a_prize_even_after_launch(): void
    {
        $prize = Prize::factory()->create([
            'competition_id' => Competition::factory()->withStatus(CompetitionStatus::Active),
            'title' => 'Old Prize',
            'value' => 100,
        ]);
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(EditPrize::class, ['record' => $prize->getRouteKey()])
            ->assertSchemaStateSet(['value' => '1.00'])
            ->fillForm(['title' => 'New Prize', 'value' => '25000'])
            ->call('save')
            ->assertHasNoFormErrors();

        $prize->refresh();
        $this->assertSame('New Prize', $prize->title);
        $this->assertSame(2_500_000, $prize->value);
    }

    public function test_super_admin_can_create_update_and_delete_prizes(): void
    {
        $competition = $this->draftCompetition();
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        Livewire::test(CreatePrize::class)
            ->fillForm(['competition_id' => $competition->id, 'title' => 'Watch', 'value' => '5000', 'quantity' => 1])
            ->call('create')
            ->assertHasNoFormErrors();

        $prize = Prize::query()->where('title', 'Watch')->firstOrFail();

        Livewire::test(EditPrize::class, ['record' => $prize->getRouteKey()])
            ->fillForm(['title' => 'Rolex'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->callAction('delete');

        $this->assertSame('Rolex', $prize->fresh()->title);
        $this->assertSoftDeleted($prize);
    }

    // ---- Relationship integrity -------------------------------------

    public function test_prize_requires_a_competition(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(CreatePrize::class)
            ->fillForm(['title' => 'Orphan', 'value' => '1', 'quantity' => 1])
            ->call('create')
            ->assertHasFormErrors(['competition_id' => 'required']);

        $this->assertSame(0, Prize::query()->count());
    }

    /**
     * Crafted Livewire payloads — the select's option list is not the
     * boundary; the submitted id is re-checked in the database.
     */
    public function test_crafted_competition_ids_are_rejected_server_side(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');

        $live = Competition::factory()->withStatus(CompetitionStatus::Active)->create();
        $trashed = $this->draftCompetition();
        $trashed->delete();

        foreach ([999_999, $live->id, $trashed->id, 'not-a-number'] as $competitionId) {
            Livewire::test(CreatePrize::class)
                ->fillForm(['title' => 'Crafted', 'value' => '1', 'quantity' => 1])
                ->set('data.competition_id', $competitionId)
                ->call('create')
                ->assertHasFormErrors(['competition_id']);
        }

        $this->assertSame(0, Prize::query()->count());
    }

    public function test_a_prize_cannot_be_moved_to_another_competition(): void
    {
        $original = $this->draftCompetition();
        $other = $this->draftCompetition();
        $prize = Prize::factory()->for($original)->create();
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        Livewire::test(EditPrize::class, ['record' => $prize->getRouteKey()])
            ->assertFormFieldDisabled('competition_id')
            ->set('data.competition_id', $other->id)
            ->fillForm(['title' => 'Still here'])
            ->call('save')
            ->assertHasNoFormErrors();

        $prize->refresh();
        $this->assertSame($original->id, $prize->competition_id);
        $this->assertSame('Still here', $prize->title);
    }

    public function test_crafted_currency_is_rejected(): void
    {
        $competition = $this->draftCompetition();
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(CreatePrize::class)
            ->fillForm(['competition_id' => $competition->id, 'title' => 'Crafted', 'value' => '1', 'quantity' => 1])
            ->set('data.currency', 'USD')
            ->call('create')
            ->assertHasFormErrors(['currency']);

        $this->assertSame(0, Prize::query()->count());
    }

    // ---- Pre-launch gate on the prize set ---------------------------

    public function test_operations_can_soft_delete_and_restore_a_pre_launch_prize(): void
    {
        $prize = Prize::factory()->for($this->draftCompetition())->create();
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(EditPrize::class, ['record' => $prize->getRouteKey()])
            ->call('mountAction', 'delete')
            ->call('callMountedAction');

        $this->assertSoftDeleted($prize);

        Livewire::test(EditPrize::class, ['record' => $prize->getRouteKey()])
            ->callAction('restore');

        $this->assertNotSoftDeleted($prize);
    }

    public function test_prizes_of_a_launched_competition_cannot_be_deleted(): void
    {
        $prize = Prize::factory()->for(Competition::factory()->withStatus(CompetitionStatus::Active))->create();
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        Livewire::test(EditPrize::class, ['record' => $prize->getRouteKey()])
            ->assertActionHidden('delete')
            ->call('mountAction', 'delete')
            ->call('callMountedAction');

        $this->assertNotSoftDeleted($prize);
    }

    public function test_prize_table_has_no_bulk_actions(): void
    {
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        $table = Livewire::test(ListPrizes::class)->instance()->getTable();

        $this->assertSame([], $table->getBulkActions());
        $this->assertSame([], $table->getToolbarActions());
    }

    // ---- Relation manager -------------------------------------------

    public function test_relation_manager_creates_prizes_attached_to_the_owner_competition(): void
    {
        $competition = $this->draftCompetition();
        $other = $this->draftCompetition();
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(PrizesRelationManager::class, [
            'ownerRecord' => $competition,
            'pageClass' => EditCompetition::class,
        ])
            ->assertFormFieldDoesNotExist('competition_id')
            ->callTableAction('create', data: [
                'title' => 'Bonus Prize',
                'value' => '100.00',
                'quantity' => 1,
            ])
            ->assertHasNoTableActionErrors();

        $prize = Prize::query()->where('title', 'Bonus Prize')->firstOrFail();
        $this->assertSame($competition->id, $prize->competition_id);
        $this->assertSame(10000, $prize->value);
        $this->assertSame('GBP', $prize->currency);
        $this->assertSame(0, $other->prizes()->count());
    }

    public function test_relation_manager_is_read_only_on_the_view_page(): void
    {
        $competition = $this->draftCompetition();
        $prize = Prize::factory()->for($competition)->create();
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(PrizesRelationManager::class, [
            'ownerRecord' => $competition,
            'pageClass' => ViewCompetition::class,
        ])
            ->assertCanSeeTableRecords([$prize])
            ->assertTableActionHidden('create')
            ->assertTableActionHidden('edit', $prize)
            ->assertTableActionHidden('delete', $prize);
    }

    public function test_relation_manager_cannot_change_the_prize_set_of_a_launched_competition(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create();
        $prize = Prize::factory()->for($competition)->create();
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        Livewire::test(PrizesRelationManager::class, [
            'ownerRecord' => $competition,
            'pageClass' => EditCompetition::class,
        ])
            ->assertTableActionHidden('create')
            ->assertTableActionHidden('delete', $prize)
            ->assertTableActionVisible('edit', $prize);

        $this->assertSame(1, $competition->prizes()->count());
    }

    public function test_prize_policy_is_enforced_by_the_resource(): void
    {
        Gate::policy(Prize::class, DenyPrizeCreatePolicy::class);
        $competition = $this->draftCompetition();
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        $this->get('/admin/prizes/create')->assertForbidden();

        Livewire::test(PrizesRelationManager::class, [
            'ownerRecord' => $competition,
            'pageClass' => EditCompetition::class,
        ])->assertTableActionHidden('create');
    }
}

class DenyPrizeCreatePolicy extends PrizePolicy
{
    public function create(Admin $admin): bool
    {
        return false;
    }
}
