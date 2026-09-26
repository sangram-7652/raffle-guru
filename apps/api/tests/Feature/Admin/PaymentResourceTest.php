<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Payments\Pages\ViewPayment;
use App\Filament\Resources\Payments\PaymentResource;
use App\Models\Admin;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Policies\PaymentPolicy;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;
use Throwable;

/**
 * P11.6 — PaymentResource: read-only operational visibility of P6
 * payment attempts. PaymentService + webhooks stay the only writers.
 */
class PaymentResourceTest extends TestCase
{
    use RefreshDatabase;

    private const SIGNATURE_SENTINEL = 'sig_SENTINEL_d3adb33f';

    private const METADATA_SENTINEL = 'META-SENTINEL-contact-9999';

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function paidPayment(array $attributes = []): Payment
    {
        $order = Order::factory()->withStatus(OrderStatus::Paid)->create([
            'reference' => 'RG-654321',
            'subtotal' => 1050,
            'total' => 1050,
        ]);

        return Payment::factory()->for($order)->paid()->create(array_merge([
            'amount' => 1050,
            'gateway_order_id' => 'order_GATEWAYORD01',
            'gateway_payment_id' => 'pay_GATEWAYPAY01',
            'gateway_signature' => self::SIGNATURE_SENTINEL,
            'metadata' => ['contact' => self::METADATA_SENTINEL],
        ], $attributes));
    }

    // ---- Access -----------------------------------------------------

    public function test_guest_is_redirected_to_admin_login(): void
    {
        $payment = $this->paidPayment();

        $this->get('/admin/payments')->assertRedirect('/admin/login');
        $this->get("/admin/payments/{$payment->id}")->assertRedirect('/admin/login');
    }

    public function test_customer_session_is_not_an_admin_session(): void
    {
        $payment = $this->paidPayment();

        $this->actingAs($payment->order->user, 'web')->get('/admin/payments')->assertRedirect('/admin/login');
        $this->actingAs($payment->order->user, 'web')->get("/admin/payments/{$payment->id}")->assertRedirect('/admin/login');
    }

    public function test_inactive_admin_is_denied(): void
    {
        $payment = $this->paidPayment();
        $this->actingAs(Admin::factory()->inactive()->create(), 'admin');

        $this->get('/admin/payments')->assertForbidden();
        $this->get("/admin/payments/{$payment->id}")->assertForbidden();
    }

    public function test_operations_and_super_admin_can_list_and_view(): void
    {
        $payment = $this->paidPayment();

        foreach ([Admin::factory()->create(), Admin::factory()->superAdmin()->create()] as $admin) {
            $this->actingAs($admin, 'admin');

            $this->get('/admin/payments')->assertOk()->assertSee('pay_GATEWAYPAY01');
            $this->get("/admin/payments/{$payment->id}")->assertOk()->assertSee('pay_GATEWAYPAY01');
        }
    }

    public function test_admin_denied_by_payment_policy_gets_403(): void
    {
        Gate::policy(Payment::class, DenyPaymentViewPolicy::class);
        $payment = $this->paidPayment();
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        $this->get('/admin/payments')->assertForbidden();
        $this->get("/admin/payments/{$payment->id}")->assertForbidden();
    }

    // ---- Read-only enforcement --------------------------------------

    public function test_no_create_edit_or_delete_routes_exist(): void
    {
        $payment = $this->paidPayment();
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        $this->assertSame(['index', 'view'], array_keys(PaymentResource::getPages()));
        $this->get('/admin/payments/create')->assertNotFound();
        $this->get("/admin/payments/{$payment->id}/edit")->assertNotFound();
    }

    public function test_resource_level_mutation_capabilities_are_all_false(): void
    {
        $payment = $this->paidPayment();
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        $this->assertTrue(PaymentResource::canViewAny());
        $this->assertFalse(PaymentResource::canCreate());
        $this->assertFalse(PaymentResource::canEdit($payment));
        $this->assertFalse(PaymentResource::canDelete($payment));
        $this->assertFalse(PaymentResource::canDeleteAny());
        $this->assertFalse(PaymentResource::canForceDelete($payment));
        $this->assertFalse(PaymentResource::canRestore($payment));
    }

    public function test_no_state_changing_actions_exist_anywhere(): void
    {
        $payment = $this->paidPayment();
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        $table = Livewire::test(ListPayments::class)->instance()->getTable();
        $this->assertSame(['view'], array_keys($table->getFlatActions()));
        $this->assertSame([], $table->getBulkActions());
        $this->assertSame([], $table->getHeaderActions());

        $view = Livewire::test(ViewPayment::class, ['record' => $payment->id]);
        $this->assertSame([], $view->instance()->getCachedHeaderActions());

        foreach (['markPaid', 'markFailed', 'refund', 'capture', 'retry', 'edit', 'delete'] as $name) {
            $view->assertActionDoesNotExist($name);
        }
    }

    /**
     * P11.6 §36 — crafted Livewire requests must not change payment
     * status or amount.
     */
    public function test_crafted_livewire_requests_cannot_mutate_the_payment(): void
    {
        $payment = $this->paidPayment();
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');
        $before = DB::table('payments')->where('id', $payment->id)->first();

        $attempts = [
            fn () => Livewire::test(ViewPayment::class, ['record' => $payment->id])->set('record.status', PaymentStatus::Refunded->value),
            fn () => Livewire::test(ViewPayment::class, ['record' => $payment->id])->set('record.amount', 1),
            fn () => Livewire::test(ViewPayment::class, ['record' => $payment->id])->set('data.status', 'failed')->call('save'),
            fn () => Livewire::test(ViewPayment::class, ['record' => $payment->id])->call('mountAction', 'refund')->call('callMountedAction'),
            fn () => Livewire::test(ViewPayment::class, ['record' => $payment->id])->call('mountAction', 'delete')->call('callMountedAction'),
            fn () => Livewire::test(ListPayments::class)->call('mountTableAction', 'delete', (string) $payment->id)->call('callMountedTableAction'),
            fn () => Livewire::test(ListPayments::class)->set('selectedTableRecords', [(string) $payment->id])->call('mountTableBulkAction', 'delete')->call('callMountedTableBulkAction'),
        ];

        foreach ($attempts as $attempt) {
            try {
                $attempt();
            } catch (Throwable) {
                // Refused outright — also a pass; the DB check is what matters.
            }
        }

        $this->assertEquals($before, DB::table('payments')->where('id', $payment->id)->first());
        $this->assertSame(OrderStatus::Paid, $payment->order->fresh()->status);
    }

    // ---- Content ------------------------------------------------------

    public function test_amount_is_displayed_as_pounds_and_stored_as_pence(): void
    {
        $payment = $this->paidPayment();
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(ViewPayment::class, ['record' => $payment->id])->assertSee('£10.50');
        Livewire::test(ListPayments::class)->assertSee('£10.50');

        $this->assertSame(1050, (int) DB::table('payments')->where('id', $payment->id)->value('amount'));
    }

    public function test_view_shows_gateway_identifiers_order_and_failure_details(): void
    {
        $payment = $this->paidPayment();
        $failed = Payment::factory()->for($payment->order)->withStatus(PaymentStatus::Failed)->create([
            'failure_code' => 'BAD_REQUEST_ERROR',
            'failure_message' => 'Payment verification failed',
        ]);
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(ViewPayment::class, ['record' => $payment->id])
            ->assertSee('pay_GATEWAYPAY01')
            ->assertSee('order_GATEWAYORD01')
            ->assertSee('razorpay')
            ->assertSee('RG-654321')
            ->assertSee($payment->order->user->email);

        Livewire::test(ViewPayment::class, ['record' => $failed->id])
            ->assertSee('BAD_REQUEST_ERROR')
            ->assertSee('Payment verification failed')
            ->assertSee('Not captured');
    }

    public function test_signature_metadata_and_customer_credentials_are_never_exposed(): void
    {
        $payment = $this->paidPayment();
        $user = $payment->order->user;
        DB::table('users')->where('id', $user->id)->update(['remember_token' => 'REMEMBER-TOKEN-SENTINEL']);
        $hash = DB::table('users')->where('id', $user->id)->value('password');
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        foreach (['/admin/payments', "/admin/payments/{$payment->id}", "/admin/orders/{$payment->order_id}"] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertDontSee(self::SIGNATURE_SENTINEL, false)
                ->assertDontSee(self::METADATA_SENTINEL, false)
                ->assertDontSee($hash, false)
                ->assertDontSee('REMEMBER-TOKEN-SENTINEL', false);
        }

        // Signatures/metadata are not searchable either.
        Livewire::test(ListPayments::class)
            ->searchTable(self::SIGNATURE_SENTINEL)
            ->assertCanNotSeeTableRecords([$payment]);
    }

    public function test_order_relationship_links_both_ways(): void
    {
        $payment = $this->paidPayment();
        $this->actingAs(Admin::factory()->create(), 'admin');

        $this->get("/admin/payments/{$payment->id}")->assertSee("/admin/orders/{$payment->order_id}", false);
        $this->get("/admin/orders/{$payment->order_id}")->assertSee("/admin/payments/{$payment->id}", false);
    }

    // ---- Table ------------------------------------------------------

    public function test_filters_status_gateway_and_date(): void
    {
        $paid = $this->paidPayment(['created_at' => '2026-09-20 10:00:00']);
        $failed = Payment::factory()->withStatus(PaymentStatus::Failed)->create(['gateway' => 'fake', 'created_at' => '2026-02-01 10:00:00']);
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(ListPayments::class)
            ->filterTable('status', [PaymentStatus::Failed->value])
            ->assertCanSeeTableRecords([$failed])
            ->assertCanNotSeeTableRecords([$paid]);

        Livewire::test(ListPayments::class)
            ->filterTable('gateway', 'razorpay')
            ->assertCanSeeTableRecords([$paid])
            ->assertCanNotSeeTableRecords([$failed]);

        Livewire::test(ListPayments::class)
            ->filterTable('created_between', ['from' => '2026-09-01'])
            ->assertCanSeeTableRecords([$paid])
            ->assertCanNotSeeTableRecords([$failed]);
    }

    public function test_search_by_gateway_id_and_order_reference(): void
    {
        $payment = $this->paidPayment();
        $other = Payment::factory()->create();
        $this->actingAs(Admin::factory()->create(), 'admin');

        foreach (['pay_GATEWAYPAY', 'order_GATEWAYORD', 'rg-6543'] as $term) {
            Livewire::test(ListPayments::class)
                ->searchTable($term)
                ->assertCanSeeTableRecords([$payment])
                ->assertCanNotSeeTableRecords([$other]);
        }
    }

    public function test_list_query_count_does_not_grow_with_rows(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');
        Payment::factory()->count(3)->for(Order::factory()->for(User::factory()))->create();

        $countQueries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::test(ListPayments::class);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $few = $countQueries();
        Payment::factory()->count(15)->create();
        $many = $countQueries();

        $this->assertSame($few, $many, 'Payment list issues per-row queries (N+1).');
    }

    public function test_navigation_group_is_commerce(): void
    {
        $this->assertSame('Commerce', PaymentResource::getNavigationGroup());
    }
}

class DenyPaymentViewPolicy extends PaymentPolicy
{
    public function viewAny(Admin $admin): bool
    {
        return false;
    }

    public function view(Admin $admin, Payment $payment): bool
    {
        return false;
    }
}
