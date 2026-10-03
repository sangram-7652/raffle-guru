<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminAbility;
use App\Enums\AdminRole;
use App\Enums\WinnerClaimStatus;
use App\Enums\WinnerStatus;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\RelationManagers\EntriesRelationManager;
use App\Filament\Resources\Customers\RelationManagers\OrdersRelationManager;
use App\Filament\Resources\Customers\RelationManagers\TicketsRelationManager;
use App\Filament\Resources\Customers\RelationManagers\WinnersRelationManager;
use App\Filament\Support\PrizeDeliveryStatusBadge;
use App\Filament\Support\WinnerClaimStatusBadge;
use App\Models\Admin;
use App\Models\Order;
use App\Models\PrizeDelivery;
use App\Models\User;
use App\Models\Winner;
use App\Policies\OrderPolicy;
use App\Policies\PrizeDeliveryPolicy;
use App\Policies\UserPolicy;
use App\Services\Winner\PrizeFulfilmentService;
use App\Services\Winner\WinnerClaimService;
use App\Services\Winner\WinnerLifecycleService;
use App\Services\Winner\WinnerService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Feature\Admin\Concerns\BuildsAllocations;
use Tests\Feature\Winner\Concerns\CreatesWinnerFixtures;
use Tests\TestCase;
use Throwable;

/**
 * P11.11 — CustomerResource: read-only customer administration.
 *
 * Covers list/search/filters, the read-only detail page and its
 * relation managers (orders, entries, tickets, winners & claims),
 * privacy, authorization for every admin/guest/customer identity, the
 * absence of any customer mutation, query-count regressions, and the
 * customer-facing API contract.
 */
class CustomerResourceTest extends TestCase
{
    use BuildsAllocations, CreatesWinnerFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /**
     * A customer who has won, carried through the REAL services to an
     * approved claim with a pending delivery (P10/P11.9/P11.10 path).
     *
     * @return array{user: User, winner: Winner}
     */
    private function winnerWithClaimAndDelivery(): array
    {
        ['competition' => $competition, 'winningUser' => $user] = $this->createDrawnCompetition();
        $winner = app(WinnerService::class)->createFromDrawResult($competition);
        $actor = Admin::factory()->create();

        app(WinnerLifecycleService::class)->transition($winner, WinnerStatus::Contacted, 'contacted');
        $claim = app(WinnerClaimService::class)->submit($winner->fresh(), 'claim notes');
        $claim = app(WinnerClaimService::class)->transition($claim, WinnerClaimStatus::VerificationPending, 'verify');
        $claim = app(WinnerClaimService::class)->transition($claim, WinnerClaimStatus::Verified, 'verified');
        app(WinnerClaimService::class)->transition($claim, WinnerClaimStatus::Approved, 'approved', null, $actor->id);
        app(PrizeFulfilmentService::class)->startFulfilment($winner->fresh(), $actor->id, 'address confirmed');

        return ['user' => $user, 'winner' => $winner->fresh()];
    }

    /**
     * Every table whose rows a customer administration could possibly
     * change. Used to prove that viewing never mutates anything.
     *
     * @return array<string, array<int, mixed>>
     */
    private function domainSnapshot(): array
    {
        $tables = [
            'users', 'orders', 'order_items', 'payments', 'entries', 'tickets',
            'winners', 'winner_claims', 'prize_deliveries',
            'winner_audit_logs', 'draw_audit_logs', 'competition_lifecycle_events',
        ];

        $snapshot = [];
        foreach ($tables as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $snapshot;
    }

    private function queryCount(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }

    // ---- Access -----------------------------------------------------

    public function test_guest_is_redirected_to_admin_login(): void
    {
        $customer = $this->allocate()->user;

        $this->get('/admin/customers')->assertRedirect('/admin/login');
        $this->get("/admin/customers/{$customer->id}")->assertRedirect('/admin/login');
    }

    public function test_customer_session_is_not_an_admin_session(): void
    {
        $customer = $this->allocate()->user;

        $this->actingAs($customer, 'web')->get('/admin/customers')->assertRedirect('/admin/login');
        $this->actingAs($customer, 'web')->get("/admin/customers/{$customer->id}")->assertRedirect('/admin/login');
    }

    public function test_inactive_admin_is_denied(): void
    {
        $customer = $this->allocate()->user;
        $this->actingAs(Admin::factory()->inactive()->create(), 'admin');

        $this->get('/admin/customers')->assertForbidden();
        $this->get("/admin/customers/{$customer->id}")->assertForbidden();
    }

    public function test_operations_and_super_admin_can_list_and_view(): void
    {
        $customer = $this->allocate()->user;

        foreach ([Admin::factory()->create(), Admin::factory()->superAdmin()->create()] as $admin) {
            $this->actingAs($admin, 'admin');

            $this->get('/admin/customers')->assertOk()->assertSee($customer->email);
            $this->get("/admin/customers/{$customer->id}")->assertOk()->assertSee($customer->name);
        }
    }

    public function test_admin_denied_by_user_policy_gets_403(): void
    {
        Gate::policy(User::class, DenyCustomerAdminViewPolicy::class);
        $customer = $this->allocate()->user;
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        $this->get('/admin/customers')->assertForbidden();
        $this->get("/admin/customers/{$customer->id}")->assertForbidden();
    }

    public function test_relation_managers_are_hidden_without_their_own_ability(): void
    {
        Gate::policy(Order::class, DenyCustomerOrderViewPolicy::class);
        $entry = $this->allocate(tickets: 2);
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        $this->assertFalse(OrdersRelationManager::canViewForRecord($entry->user, ViewCustomer::class));

        $managers = Livewire::test(ViewCustomer::class, ['record' => $entry->user->getRouteKey()])
            ->instance()
            ->getRelationManagers();

        $this->assertNotContains(OrdersRelationManager::class, $managers);
        $this->assertContains(EntriesRelationManager::class, $managers);
    }

    /**
     * Operations holds CustomerView and nothing that edits customers.
     * Asserted from the role matrix, not from a page render, so a
     * future role change shows up as a diff here.
     */
    public function test_operations_holds_only_customer_view_and_no_customer_mutation_ability(): void
    {
        $customerAbilities = array_values(array_filter(
            array_map(fn (AdminAbility $a): string => $a->value, AdminAbility::cases()),
            fn (string $value): bool => str_starts_with($value, 'customer.'),
        ));

        $this->assertSame(['customer.view'], $customerAbilities);
        $this->assertContains(AdminAbility::CustomerView, AdminRole::Operations->abilities());
    }

    // ---- Read-only enforcement --------------------------------------

    public function test_no_create_edit_delete_or_restore_routes_or_capabilities(): void
    {
        $customer = $this->allocate()->user;
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        $this->assertSame(['index', 'view'], array_keys(CustomerResource::getPages()));
        $this->get('/admin/customers/create')->assertNotFound();
        $this->get("/admin/customers/{$customer->id}/edit")->assertNotFound();

        $this->assertTrue(CustomerResource::canViewAny());
        foreach (['canCreate', 'canDeleteAny', 'canForceDeleteAny', 'canRestoreAny', 'canReorder'] as $method) {
            $this->assertFalse(CustomerResource::{$method}(), $method);
        }
        foreach (['canEdit', 'canDelete', 'canForceDelete', 'canRestore', 'canReplicate'] as $method) {
            $this->assertFalse(CustomerResource::{$method}($customer), $method);
        }
    }

    public function test_list_has_only_view_and_no_bulk_or_header_actions(): void
    {
        $this->allocate();
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        $table = Livewire::test(ListCustomers::class)->instance()->getTable();
        $this->assertSame(['view'], array_keys($table->getFlatActions()));
        $this->assertSame([], $table->getBulkActions());
        $this->assertSame([], $table->getHeaderActions());
    }

    public function test_every_relation_manager_is_read_only_with_no_actions(): void
    {
        $entry = $this->allocate(tickets: 2);
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        foreach ([OrdersRelationManager::class, EntriesRelationManager::class, TicketsRelationManager::class, WinnersRelationManager::class] as $class) {
            $manager = Livewire::test($class, ['ownerRecord' => $entry->user, 'pageClass' => ViewCustomer::class]);
            $this->assertTrue($manager->instance()->isReadOnly(), $class);

            $table = $manager->instance()->getTable();
            $this->assertSame([], $table->getFlatActions(), $class);
            $this->assertSame([], $table->getBulkActions(), $class);
            $this->assertSame([], $table->getHeaderActions(), $class);
        }
    }

    /**
     * Forged mutations of customer fields, of history, and of the
     * customer's account state — through every surface a crafted
     * request could reach. Nothing may change.
     */
    public function test_crafted_requests_cannot_mutate_the_customer_or_their_history(): void
    {
        ['user' => $user, 'winner' => $winner] = $this->winnerWithClaimAndDelivery();
        $entry = $this->allocate(tickets: 2, user: $user);
        $order = $entry->order;
        $ticket = $entry->tickets()->first();
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        $before = $this->domainSnapshot();

        $attempts = [
            fn () => Livewire::test(ViewCustomer::class, ['record' => $user->getRouteKey()])->set('record.email', 'hijack@example.com'),
            fn () => Livewire::test(ViewCustomer::class, ['record' => $user->getRouteKey()])->set('record.name', 'Hijacked'),
            fn () => Livewire::test(ViewCustomer::class, ['record' => $user->getRouteKey()])->set('record.password', 'new-secret'),
            fn () => Livewire::test(ViewCustomer::class, ['record' => $user->getRouteKey()])->set('record.deleted_at', now()),
            fn () => Livewire::test(ViewCustomer::class, ['record' => $user->getRouteKey()])->call('mountAction', 'archive')->call('callMountedAction'),
            fn () => Livewire::test(ViewCustomer::class, ['record' => $user->getRouteKey()])->call('mountAction', 'restore')->call('callMountedAction'),
            fn () => Livewire::test(ListCustomers::class)->call('mountTableAction', 'delete', (string) $user->id)->call('callMountedTableAction'),
            fn () => Livewire::test(ListCustomers::class)->call('mountTableAction', 'restore', (string) $user->id)->call('callMountedTableAction'),
            fn () => Livewire::test(ListCustomers::class)->set('selectedTableRecords', [(string) $user->id])->call('mountTableBulkAction', 'delete')->call('callMountedTableBulkAction'),
            fn () => Livewire::test(OrdersRelationManager::class, ['ownerRecord' => $user, 'pageClass' => ViewCustomer::class])
                ->call('mountTableAction', 'delete', (string) $order->id)->call('callMountedTableAction'),
            fn () => Livewire::test(TicketsRelationManager::class, ['ownerRecord' => $user, 'pageClass' => ViewCustomer::class])
                ->call('mountTableAction', 'delete', (string) $ticket->id)->call('callMountedTableAction'),
            fn () => Livewire::test(WinnersRelationManager::class, ['ownerRecord' => $user, 'pageClass' => ViewCustomer::class])
                ->call('mountTableAction', 'markContacted', (string) $winner->id)->call('callMountedTableAction'),
        ];

        foreach ($attempts as $attempt) {
            try {
                $attempt();
            } catch (Throwable) {
                // Refused outright — also a pass; the DB check is what matters.
            }
        }

        foreach (['put', 'patch', 'delete'] as $method) {
            $response = $this->{$method}("/admin/customers/{$user->id}", ['email' => 'hijack@example.com']);
            $this->assertContains($response->getStatusCode(), [404, 405], "{$method} must not be routable");
        }

        $this->assertSame($before, $this->domainSnapshot());
    }

    public function test_no_archive_or_restore_is_offered_because_no_domain_operation_exists(): void
    {
        $customer = $this->allocate()->user;
        $superAdmin = Admin::factory()->superAdmin()->create();

        // No policy method exists for these, so Gate denies by default.
        foreach (['update', 'delete', 'restore', 'forceDelete', 'archive', 'unarchive'] as $ability) {
            $this->assertFalse(Gate::forUser($superAdmin)->allows($ability, $customer), $ability);
        }

        $this->assertFalse(CustomerResource::canDelete($customer));
        $this->assertFalse(CustomerResource::canRestore($customer));
    }

    // ---- Search -----------------------------------------------------

    public function test_name_and_email_search_are_prefix_matches(): void
    {
        $ann = User::factory()->create(['name' => 'Ann Smith', 'email' => 'ann.smith@example.com']);
        $joanna = User::factory()->create(['name' => 'Joanna Ann', 'email' => 'joanna@example.com']);
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(ListCustomers::class)
            ->searchTable('Ann')
            ->assertCanSeeTableRecords([$ann])
            ->assertCanNotSeeTableRecords([$joanna]);

        Livewire::test(ListCustomers::class)
            ->searchTable('ann.smith@')
            ->assertCanSeeTableRecords([$ann])
            ->assertCanNotSeeTableRecords([$joanna]);

        Livewire::test(ListCustomers::class)
            ->searchTable('smith@example')
            ->assertCanNotSeeTableRecords([$ann, $joanna]);
    }

    // ---- Filters ----------------------------------------------------

    public function test_filters_on_verification_orders_entries_registration_and_archive_state(): void
    {
        $withHistory = $this->allocate(tickets: 2)->user;
        $noHistory = User::factory()->unverified()->create();
        DB::table('users')->where('id', $noHistory->id)->update(['created_at' => '2026-01-05 10:00:00']);
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(ListCustomers::class)->filterTable('email_verified', false)
            ->assertCanSeeTableRecords([$noHistory])
            ->assertCanNotSeeTableRecords([$withHistory]);

        Livewire::test(ListCustomers::class)->filterTable('email_verified', true)
            ->assertCanSeeTableRecords([$withHistory])
            ->assertCanNotSeeTableRecords([$noHistory]);

        Livewire::test(ListCustomers::class)->filterTable('has_orders', true)
            ->assertCanSeeTableRecords([$withHistory])
            ->assertCanNotSeeTableRecords([$noHistory]);

        Livewire::test(ListCustomers::class)->filterTable('has_orders', false)
            ->assertCanSeeTableRecords([$noHistory])
            ->assertCanNotSeeTableRecords([$withHistory]);

        Livewire::test(ListCustomers::class)->filterTable('has_entries', true)
            ->assertCanSeeTableRecords([$withHistory])
            ->assertCanNotSeeTableRecords([$noHistory]);

        Livewire::test(ListCustomers::class)->filterTable('created_between', ['from' => '2026-09-01'])
            ->assertCanSeeTableRecords([$withHistory])
            ->assertCanNotSeeTableRecords([$noHistory]);
    }

    public function test_archived_customers_are_hidden_by_default_and_shown_only_by_the_trashed_filter(): void
    {
        $active = User::factory()->create();
        $archived = User::factory()->create();
        $archived->delete(); // fixture only: no production path archives a customer
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(ListCustomers::class)
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$archived]);

        Livewire::test(ListCustomers::class)->filterTable('trashed', false)
            ->assertCanSeeTableRecords([$archived])
            ->assertCanNotSeeTableRecords([$active]);

        Livewire::test(ListCustomers::class)->filterTable('trashed', true)
            ->assertCanSeeTableRecords([$active, $archived]);
    }

    // ---- Content ----------------------------------------------------

    public function test_detail_shows_profile_contact_account_and_activity(): void
    {
        $entry = $this->allocate(tickets: 2);
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(ViewCustomer::class, ['record' => $entry->user->getRouteKey()])
            ->assertOk()
            ->assertSee('Customer profile')
            ->assertSee('Contact')
            ->assertSee($entry->user->email)
            ->assertSee('Account')
            ->assertSee('Active')
            ->assertSee('Activity')
            ->assertSee('Orders')
            ->assertSee('Entries')
            ->assertSee('Tickets')
            ->assertSee('Winners &amp; claims', false);
    }

    public function test_orders_display_reference_status_competition_and_total(): void
    {
        $entry = $this->allocate(tickets: 3);
        $order = $entry->order;
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(OrdersRelationManager::class, ['ownerRecord' => $entry->user, 'pageClass' => ViewCustomer::class])
            ->assertCanSeeTableRecords([$order])
            ->assertSee($order->reference)
            ->assertSee($entry->competition->title)
            ->assertSee('10.50');
    }

    public function test_entries_display_competition_requested_and_allocated_quantities(): void
    {
        $entry = $this->allocate(tickets: 3);
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(EntriesRelationManager::class, ['ownerRecord' => $entry->user, 'pageClass' => ViewCustomer::class])
            ->assertCanSeeTableRecords([$entry])
            ->assertSee($entry->competition->title)
            ->assertTableColumnStateSet('quantity', 3, record: $entry)
            ->assertTableColumnStateSet('tickets_count', 3, record: $entry);
    }

    public function test_tickets_display_ticket_numbers_read_only(): void
    {
        $entry = $this->allocate(tickets: 3, firstTicketNumber: 41);
        $tickets = $entry->tickets()->orderBy('ticket_number')->get();
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(TicketsRelationManager::class, ['ownerRecord' => $entry->user, 'pageClass' => ViewCustomer::class])
            ->assertCanSeeTableRecords($tickets)
            ->assertSee('41')
            ->assertSee('43');
    }

    public function test_winners_and_claims_display_with_fulfilment_status_for_an_admin_with_it(): void
    {
        ['user' => $user, 'winner' => $winner] = $this->winnerWithClaimAndDelivery();
        $this->actingAs(Admin::factory()->create(), 'admin');

        $delivery = PrizeDelivery::query()->where('winner_id', $winner->id)->sole();

        Livewire::test(WinnersRelationManager::class, ['ownerRecord' => $user, 'pageClass' => ViewCustomer::class])
            ->assertCanSeeTableRecords([$winner])
            ->assertSee($winner->ticket->ticket_number)
            ->assertSee($delivery->prize->title)
            ->assertSee(WinnerClaimStatusBadge::label(WinnerClaimStatus::Approved))
            ->assertSee(PrizeDeliveryStatusBadge::label($delivery->status));
    }

    public function test_claim_and_fulfilment_columns_are_hidden_without_their_own_abilities(): void
    {
        ['user' => $user] = $this->winnerWithClaimAndDelivery();
        Gate::policy(PrizeDelivery::class, DenyCustomerPrizeDeliveryViewPolicy::class);
        $this->actingAs(Admin::factory()->create(), 'admin');

        $table = Livewire::test(WinnersRelationManager::class, ['ownerRecord' => $user, 'pageClass' => ViewCustomer::class])
            ->instance()
            ->getTable();

        $visible = array_keys(array_filter($table->getColumns(), fn ($column): bool => $column->isVisible()));

        $this->assertContains('claim.status', $visible);
        $this->assertNotContains('delivery.status', $visible);
    }

    public function test_archived_customer_remains_viewable_as_history(): void
    {
        $entry = $this->allocate(tickets: 2);
        $entry->user->delete(); // fixture only
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(ViewCustomer::class, ['record' => $entry->user->getRouteKey()])
            ->assertOk()
            ->assertSee('Archived');

        Livewire::test(OrdersRelationManager::class, ['ownerRecord' => $entry->user, 'pageClass' => ViewCustomer::class])
            ->assertCanSeeTableRecords([$entry->order]);
    }

    // ---- Privacy ----------------------------------------------------

    public function test_password_hash_tokens_and_sessions_are_never_exposed(): void
    {
        $customer = $this->allocate()->user;
        $hash = DB::table('users')->where('id', $customer->id)->value('password');
        DB::table('users')->where('id', $customer->id)->update(['remember_token' => 'REMEMBER-TOKEN-SENTINEL']);
        DB::table('sessions')->insert([
            'id' => 'session-sentinel',
            'user_id' => $customer->id,
            'payload' => 'SESSION-PAYLOAD-SENTINEL',
            'last_activity' => time(),
        ]);
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        foreach (['/admin/customers', "/admin/customers/{$customer->id}"] as $url) {
            $this->get($url)->assertOk()
                ->assertDontSee($hash, false)
                ->assertDontSee('$2y$', false)
                ->assertDontSee('REMEMBER-TOKEN-SENTINEL', false)
                ->assertDontSee('SESSION-PAYLOAD-SENTINEL', false)
                ->assertDontSee('session-sentinel', false);
        }
    }

    public function test_customer_query_never_selects_credential_columns(): void
    {
        $customer = $this->allocate()->user;

        $loaded = CustomerResource::getEloquentQuery()->findOrFail($customer->id);
        $this->assertNotContains('password', array_keys($loaded->getAttributes()));
        $this->assertNotContains('remember_token', array_keys($loaded->getAttributes()));

        $viewed = CustomerResource::getRecordRouteBindingEloquentQuery()->findOrFail($customer->id);
        $this->assertNotContains('password', array_keys($viewed->getAttributes()));
        $this->assertNotContains('remember_token', array_keys($viewed->getAttributes()));
    }

    // ---- Customer-facing API ----------------------------------------

    public function test_customer_api_contract_is_unchanged_by_the_admin_resource(): void
    {
        $customer = $this->allocate()->user;

        $response = $this->actingAs($customer)->getJson('/api/v1/auth/user');

        $response->assertOk();
        $this->assertSame(['id', 'name', 'email', 'email_verified_at'], array_keys($response->json('data')));
        $response->assertJsonMissingPath('data.password');
        $response->assertJsonMissingPath('data.remember_token');
    }

    public function test_customer_cannot_reach_the_admin_panel_or_its_customer_data(): void
    {
        $customer = $this->allocate()->user;

        $this->actingAs($customer, 'web')->get('/admin/customers')->assertRedirect('/admin/login');
        $this->actingAs($customer, 'web')->get("/admin/customers/{$customer->id}")->assertRedirect('/admin/login');
        $this->actingAs($customer, 'web')->get('/admin')->assertRedirect('/admin/login');
    }

    // ---- Audit ------------------------------------------------------

    public function test_viewing_customer_pages_changes_no_domain_or_audit_state(): void
    {
        ['user' => $user] = $this->winnerWithClaimAndDelivery();
        $this->allocate(tickets: 2, user: $user);
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        $before = $this->domainSnapshot();

        Livewire::test(ListCustomers::class)->searchTable($user->name)->assertOk();
        Livewire::test(ViewCustomer::class, ['record' => $user->getRouteKey()])->assertOk();
        Livewire::test(OrdersRelationManager::class, ['ownerRecord' => $user, 'pageClass' => ViewCustomer::class])->assertOk();
        Livewire::test(WinnersRelationManager::class, ['ownerRecord' => $user, 'pageClass' => ViewCustomer::class])->assertOk();

        $this->assertSame($before, $this->domainSnapshot());
    }

    // ---- Query regressions ------------------------------------------

    public function test_list_query_count_does_not_grow_with_customers_or_history(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');

        $count = fn (): int => $this->queryCount(fn () => Livewire::test(ListCustomers::class));

        $this->allocate(tickets: 2);
        $few = $count();

        foreach (range(1, 6) as $i) {
            $this->allocate(tickets: 2);
        }

        $this->assertGreaterThan(0, $few, 'query log was not captured');
        $this->assertSame($few, $count(), 'Customer list N+1');
    }

    public function test_detail_query_count_does_not_grow_with_history_rows(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');
        $user = $this->allocate(tickets: 2)->user;

        $count = fn (): int => $this->queryCount(fn () => Livewire::test(ViewCustomer::class, ['record' => $user->getRouteKey()]));

        $few = $count();

        foreach (range(1, 5) as $i) {
            $this->allocate(tickets: 2, user: $user);
        }

        $this->assertGreaterThan(0, $few, 'query log was not captured');
        $this->assertSame($few, $count(), 'Customer detail N+1');
    }

    public function test_navigation_group(): void
    {
        $this->assertSame('Commerce', CustomerResource::getNavigationGroup());
    }
}

class DenyCustomerAdminViewPolicy extends UserPolicy
{
    public function viewAny(Admin $admin): bool
    {
        return false;
    }

    public function view(Admin $admin, User $customer): bool
    {
        return false;
    }
}

class DenyCustomerOrderViewPolicy extends OrderPolicy
{
    public function viewAny(Admin $admin): bool
    {
        return false;
    }

    public function view(Admin $admin, Order $order): bool
    {
        return false;
    }
}

class DenyCustomerPrizeDeliveryViewPolicy extends PrizeDeliveryPolicy
{
    public function viewAny(Admin $admin): bool
    {
        return false;
    }

    public function view(Admin $admin, PrizeDelivery $delivery): bool
    {
        return false;
    }
}
