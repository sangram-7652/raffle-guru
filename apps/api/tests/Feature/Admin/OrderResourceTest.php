<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Admin;
use App\Models\Competition;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\User;
use App\Policies\OrderPolicy;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;
use Throwable;

/**
 * P11.6 — OrderResource: read-only operational visibility of P5 orders.
 */
class OrderResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function orderWithItem(array $orderAttributes = []): Order
    {
        $order = Order::factory()->create(array_merge([
            'reference' => 'RG-123456',
            'status' => OrderStatus::Paid,
            'subtotal' => 1050,
            'total' => 1050,
        ], $orderAttributes));

        OrderItem::factory()->for($order)->create([
            'competition_title' => 'Porsche 911 Snapshot Title',
            'quantity' => 3,
            'unit_price' => 350,
            'line_total' => 1050,
        ]);

        return $order;
    }

    // ---- Access -----------------------------------------------------

    public function test_guest_is_redirected_to_admin_login(): void
    {
        $order = $this->orderWithItem();

        $this->get('/admin/orders')->assertRedirect('/admin/login');
        $this->get("/admin/orders/{$order->id}")->assertRedirect('/admin/login');
    }

    public function test_customer_session_is_not_an_admin_session(): void
    {
        $order = $this->orderWithItem();

        $this->actingAs($order->user, 'web')->get('/admin/orders')->assertRedirect('/admin/login');
        $this->actingAs($order->user, 'web')->get("/admin/orders/{$order->id}")->assertRedirect('/admin/login');
    }

    public function test_inactive_admin_is_denied(): void
    {
        $order = $this->orderWithItem();
        $this->actingAs(Admin::factory()->inactive()->create(), 'admin');

        $this->get('/admin/orders')->assertForbidden();
        $this->get("/admin/orders/{$order->id}")->assertForbidden();
    }

    public function test_operations_and_super_admin_can_list_and_view(): void
    {
        $order = $this->orderWithItem();

        foreach ([Admin::factory()->create(), Admin::factory()->superAdmin()->create()] as $admin) {
            $this->actingAs($admin, 'admin');

            $this->get('/admin/orders')->assertOk()->assertSee('RG-123456');
            $this->get("/admin/orders/{$order->id}")->assertOk()->assertSee('RG-123456');
        }
    }

    public function test_admin_denied_by_order_policy_gets_403(): void
    {
        Gate::policy(Order::class, DenyOrderViewPolicy::class);
        $order = $this->orderWithItem();
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        $this->get('/admin/orders')->assertForbidden();
        $this->get("/admin/orders/{$order->id}")->assertForbidden();
    }

    // ---- Read-only enforcement --------------------------------------

    public function test_no_create_edit_or_delete_routes_exist(): void
    {
        $order = $this->orderWithItem();
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        $this->assertSame(['index', 'view'], array_keys(OrderResource::getPages()));
        $this->get('/admin/orders/create')->assertNotFound();
        $this->get("/admin/orders/{$order->id}/edit")->assertNotFound();
    }

    public function test_resource_level_mutation_capabilities_are_all_false(): void
    {
        $order = $this->orderWithItem();
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        $this->assertTrue(OrderResource::canViewAny());
        $this->assertTrue(OrderResource::canView($order));
        $this->assertFalse(OrderResource::canCreate());
        $this->assertFalse(OrderResource::canEdit($order));
        $this->assertFalse(OrderResource::canDelete($order));
        $this->assertFalse(OrderResource::canDeleteAny());
        $this->assertFalse(OrderResource::canForceDelete($order));
        $this->assertFalse(OrderResource::canRestore($order));
        $this->assertFalse(OrderResource::canReplicate($order));
    }

    public function test_table_has_only_the_view_action_and_no_bulk_actions(): void
    {
        $this->orderWithItem();
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        $table = Livewire::test(ListOrders::class)->instance()->getTable();

        $this->assertSame(['view'], array_keys($table->getFlatActions()));
        $this->assertSame([], $table->getBulkActions());
        $this->assertSame([], $table->getToolbarActions());
        $this->assertSame([], $table->getHeaderActions());
    }

    /**
     * P11.6 §36 — crafted Livewire requests must not be able to change
     * order status, totals, or line prices.
     */
    public function test_crafted_livewire_requests_cannot_mutate_the_order(): void
    {
        $order = $this->orderWithItem();
        $item = $order->items()->firstOrFail();
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        $before = [
            DB::table('orders')->where('id', $order->id)->first(),
            DB::table('order_items')->where('id', $item->id)->first(),
        ];

        $attempts = [
            fn () => Livewire::test(ViewOrder::class, ['record' => $order->id])->set('record.status', OrderStatus::Refunded->value),
            fn () => Livewire::test(ViewOrder::class, ['record' => $order->id])->set('record.total', 1),
            fn () => Livewire::test(ViewOrder::class, ['record' => $order->id])->set('data.status', 'cancelled')->call('save'),
            fn () => Livewire::test(ViewOrder::class, ['record' => $order->id])->call('mountAction', 'edit')->call('callMountedAction'),
            fn () => Livewire::test(ViewOrder::class, ['record' => $order->id])->call('mountAction', 'delete')->call('callMountedAction'),
            fn () => Livewire::test(ListOrders::class)->call('mountTableAction', 'delete', (string) $order->id)->call('callMountedTableAction'),
            fn () => Livewire::test(ListOrders::class)->call('mountTableAction', 'edit', (string) $order->id)->call('callMountedTableAction'),
            fn () => Livewire::test(ListOrders::class)->set('selectedTableRecords', [(string) $order->id])->call('mountTableBulkAction', 'delete')->call('callMountedTableBulkAction'),
        ];

        foreach ($attempts as $attempt) {
            try {
                $attempt();
            } catch (Throwable) {
                // Livewire refusing the request outright is also a pass —
                // what matters is that nothing below changed.
            }
        }

        $this->assertEquals($before, [
            DB::table('orders')->where('id', $order->id)->first(),
            DB::table('order_items')->where('id', $item->id)->first(),
        ]);
        $this->assertNotSoftDeleted($order);
    }

    // ---- Content ------------------------------------------------------

    public function test_view_shows_persisted_items_money_customer_and_payments(): void
    {
        $order = $this->orderWithItem();
        Payment::factory()->for($order)->paid()->create(['amount' => 1050, 'gateway_payment_id' => 'pay_VISIBLEREF123']);
        Payment::factory()->for($order)->withStatus(PaymentStatus::Failed)->create(['failure_message' => 'Card declined by issuer']);
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(ViewOrder::class, ['record' => $order->id])
            ->assertOk()
            ->assertSee('RG-123456')
            ->assertSee('£10.50')
            ->assertSee('£3.50')
            ->assertSee('Porsche 911 Snapshot Title')
            ->assertSee($order->user->name)
            ->assertSee($order->user->email)
            ->assertSee('Card declined by issuer')
            ->assertSee('Paid')
            ->assertSee('Failed');

        // Display formatting never touches persistence.
        $this->assertSame(1050, (int) DB::table('orders')->where('id', $order->id)->value('total'));
    }

    public function test_view_shows_stored_total_not_a_recalculated_one(): void
    {
        // Deliberately inconsistent history: the stored total must be what
        // is shown, never a PHP re-sum of the line items.
        $order = $this->orderWithItem(['subtotal' => 999, 'total' => 999]);
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(ViewOrder::class, ['record' => $order->id])
            ->assertSee('£9.99')
            ->assertSee('£10.50'); // the line total, as persisted
    }

    public function test_sensitive_customer_fields_are_never_exposed(): void
    {
        $user = User::factory()->create(['remember_token' => 'REMEMBER-TOKEN-SENTINEL']);
        $order = $this->orderWithItem(['user_id' => $user->id]);
        $hash = DB::table('users')->where('id', $user->id)->value('password');
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        foreach (['/admin/orders', "/admin/orders/{$order->id}"] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertDontSee($hash, false)
                ->assertDontSee('REMEMBER-TOKEN-SENTINEL', false);
        }

        // The eager-loaded customer never even carries the credentials.
        $loaded = OrderResource::getEloquentQuery()->findOrFail($order->id)->user;
        $this->assertSame(['id', 'name', 'email'], array_keys($loaded->getAttributes()));
    }

    // ---- Table: filters, search, sort, pagination -------------------

    public function test_status_filter(): void
    {
        $paid = Order::factory()->withStatus(OrderStatus::Paid)->create();
        $failed = Order::factory()->withStatus(OrderStatus::Failed)->create();
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(ListOrders::class)
            ->filterTable('status', [OrderStatus::Failed->value])
            ->assertCanSeeTableRecords([$failed])
            ->assertCanNotSeeTableRecords([$paid]);
    }

    public function test_date_range_filter(): void
    {
        $old = Order::factory()->create(['created_at' => '2026-01-10 12:00:00']);
        $recent = Order::factory()->create(['created_at' => '2026-09-20 12:00:00']);
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(ListOrders::class)
            ->filterTable('created_between', ['from' => '2026-09-01', 'until' => '2026-09-30'])
            ->assertCanSeeTableRecords([$recent])
            ->assertCanNotSeeTableRecords([$old]);
    }

    public function test_customer_and_competition_filters(): void
    {
        $competition = Competition::factory()->create();
        $mine = Order::factory()->create();
        OrderItem::factory()->for($mine)->create(['competition_id' => $competition->id]);
        $other = Order::factory()->create();
        OrderItem::factory()->for($other)->create();
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(ListOrders::class)
            ->filterTable('user', $mine->user_id)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$other]);

        Livewire::test(ListOrders::class)
            ->filterTable('competition', ['competition_id' => $competition->id])
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_search_by_reference_prefix_and_customer_email(): void
    {
        $target = Order::factory()->create([
            'reference' => 'RG-777001',
            'user_id' => User::factory()->create(['email' => 'jane.target@example.com']),
        ]);
        $other = Order::factory()->create(['reference' => 'RG-111001']);
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(ListOrders::class)
            ->searchTable('rg-777')
            ->assertCanSeeTableRecords([$target])
            ->assertCanNotSeeTableRecords([$other]);

        Livewire::test(ListOrders::class)
            ->searchTable('jane.target')
            ->assertCanSeeTableRecords([$target])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_sorting_by_total(): void
    {
        $small = Order::factory()->create(['subtotal' => 100, 'total' => 100]);
        $large = Order::factory()->create(['subtotal' => 9000, 'total' => 9000]);
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(ListOrders::class)
            ->sortTable('total', 'desc')
            ->assertCanSeeTableRecords([$large, $small], inOrder: true);
    }

    public function test_pagination_is_server_side(): void
    {
        Order::factory()->count(12)->create();
        $this->actingAs(Admin::factory()->create(), 'admin');

        $component = Livewire::test(ListOrders::class)->set('tableRecordsPerPage', 5);

        $this->assertCount(5, $component->instance()->getTableRecords());
        $this->assertSame(12, $component->instance()->getTableRecords()->total());
    }

    public function test_list_query_count_does_not_grow_with_rows(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');
        Payment::factory()->count(3)->create();

        $countQueries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::test(ListOrders::class);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $few = $countQueries();
        Payment::factory()->count(15)->create();
        $many = $countQueries();

        $this->assertSame($few, $many, 'Order list issues per-row queries (N+1).');
    }

    public function test_navigation_group_is_commerce(): void
    {
        $this->assertSame('Commerce', OrderResource::getNavigationGroup());
    }
}

class DenyOrderViewPolicy extends OrderPolicy
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
