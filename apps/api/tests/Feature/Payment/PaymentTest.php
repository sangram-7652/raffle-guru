<?php

namespace Tests\Feature\Payment;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\FakePaymentGateway;
use App\Services\Payments\PaymentGatewayInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(PaymentGatewayInterface::class, new FakePaymentGateway());
    }

    // -- Initiation --------------------------------------------------

    public function test_unauthenticated_payment_initiation_is_rejected(): void
    {
        $order = Order::factory()->create();

        $this->postJson("/api/v1/orders/{$order->reference}/payment")->assertUnauthorized();
    }

    public function test_cross_user_order_payment_initiation_is_rejected(): void
    {
        $owner = User::factory()->create();
        $order = Order::factory()->for($owner)->create();
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->postJson("/api/v1/orders/{$order->reference}/payment")->assertNotFound();
    }

    public function test_payment_initiation_creates_a_gateway_order_and_returns_safe_checkout_data(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->create(['total' => 1500, 'currency' => 'GBP']);

        $response = $this->actingAs($user)->postJson("/api/v1/orders/{$order->reference}/payment");

        $response->assertCreated()
            ->assertJsonPath('data.amount', 1500)
            ->assertJsonPath('data.currency', 'GBP')
            ->assertJsonPath('data.reference', $order->reference)
            ->assertJsonStructure(['data' => ['key_id', 'gateway_order_id', 'amount', 'currency', 'reference', 'name']]);

        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'amount' => 1500,
            'currency' => 'GBP',
            'status' => PaymentStatus::Created->value,
        ]);
    }

    public function test_payment_amount_is_the_servers_order_total_never_client_supplied(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->create(['total' => 2000]);

        // No body is even accepted by this endpoint — an attempt to smuggle
        // an amount is simply ignored.
        $response = $this->actingAs($user)->postJson("/api/v1/orders/{$order->reference}/payment", [
            'amount' => 1,
            'currency' => 'USD',
        ]);

        $response->assertCreated()->assertJsonPath('data.amount', 2000)->assertJsonPath('data.currency', 'GBP');
    }

    public function test_paid_order_cannot_be_paid_again(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->withStatus(OrderStatus::Paid)->create();

        $this->actingAs($user)->postJson("/api/v1/orders/{$order->reference}/payment")->assertUnprocessable();
    }

    public function test_cancelled_order_cannot_be_paid(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->withStatus(OrderStatus::Cancelled)->create();

        $this->actingAs($user)->postJson("/api/v1/orders/{$order->reference}/payment")->assertUnprocessable();
    }

    public function test_completed_order_cannot_be_paid(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->withStatus(OrderStatus::Completed)->create();

        $this->actingAs($user)->postJson("/api/v1/orders/{$order->reference}/payment")->assertUnprocessable();
    }

    public function test_pending_order_remains_payable(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->withStatus(OrderStatus::Pending)->create();

        $this->actingAs($user)->postJson("/api/v1/orders/{$order->reference}/payment")->assertCreated();
    }

    // -- Verification --------------------------------------------------

    public function test_unauthenticated_verification_is_rejected(): void
    {
        $this->postJson('/api/v1/payments/verify', [
            'razorpay_order_id' => 'order_x',
            'razorpay_payment_id' => 'pay_x',
            'razorpay_signature' => 'sig',
        ])->assertUnauthorized();
    }

    public function test_successful_verification_marks_payment_and_order_paid(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->withStatus(OrderStatus::Pending)->create(['total' => 500, 'currency' => 'GBP']);
        $init = $this->actingAs($user)->postJson("/api/v1/orders/{$order->reference}/payment");
        $gatewayOrderId = $init->json('data.gateway_order_id');

        $paymentId = 'pay_ok_123';
        $signature = FakePaymentGateway::sign($gatewayOrderId, $paymentId);

        $response = $this->actingAs($user)->postJson('/api/v1/payments/verify', [
            'razorpay_order_id' => $gatewayOrderId,
            'razorpay_payment_id' => $paymentId,
            'razorpay_signature' => $signature,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.payment.status', 'paid');

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => OrderStatus::Paid->value]);
        $this->assertDatabaseHas('payments', [
            'gateway_order_id' => $gatewayOrderId,
            'gateway_payment_id' => $paymentId,
            'status' => PaymentStatus::Paid->value,
        ]);
    }

    public function test_verification_with_invalid_signature_is_rejected_and_marks_payment_failed(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->withStatus(OrderStatus::Pending)->create();
        $init = $this->actingAs($user)->postJson("/api/v1/orders/{$order->reference}/payment");
        $gatewayOrderId = $init->json('data.gateway_order_id');

        $response = $this->actingAs($user)->postJson('/api/v1/payments/verify', [
            'razorpay_order_id' => $gatewayOrderId,
            'razorpay_payment_id' => 'pay_ok_123',
            'razorpay_signature' => 'not-a-real-signature',
        ]);

        $response->assertUnprocessable();
        $this->assertDatabaseHas('payments', ['gateway_order_id' => $gatewayOrderId, 'status' => PaymentStatus::Failed->value]);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => OrderStatus::Pending->value]);
    }

    public function test_verification_rejects_a_tampered_amount(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->withStatus(OrderStatus::Pending)->create();
        $init = $this->actingAs($user)->postJson("/api/v1/orders/{$order->reference}/payment");
        $gatewayOrderId = $init->json('data.gateway_order_id');

        // "pay_mismatch_" makes FakePaymentGateway::fetchPayment() report a
        // different amount than what this app stored — simulating a
        // gateway that disagrees with the client's claim.
        $paymentId = 'pay_mismatch_456';
        $signature = FakePaymentGateway::sign($gatewayOrderId, $paymentId);

        $response = $this->actingAs($user)->postJson('/api/v1/payments/verify', [
            'razorpay_order_id' => $gatewayOrderId,
            'razorpay_payment_id' => $paymentId,
            'razorpay_signature' => $signature,
        ]);

        $response->assertUnprocessable();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => OrderStatus::Pending->value]);
    }

    public function test_duplicate_verification_is_idempotent(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->withStatus(OrderStatus::Pending)->create();
        $init = $this->actingAs($user)->postJson("/api/v1/orders/{$order->reference}/payment");
        $gatewayOrderId = $init->json('data.gateway_order_id');
        $paymentId = 'pay_ok_789';
        $signature = FakePaymentGateway::sign($gatewayOrderId, $paymentId);
        $payload = [
            'razorpay_order_id' => $gatewayOrderId,
            'razorpay_payment_id' => $paymentId,
            'razorpay_signature' => $signature,
        ];

        $this->actingAs($user)->postJson('/api/v1/payments/verify', $payload)->assertOk();
        $second = $this->actingAs($user)->postJson('/api/v1/payments/verify', $payload);

        $second->assertOk()->assertJsonPath('data.status', 'paid');
        $this->assertSame(1, Payment::query()->where('gateway_order_id', $gatewayOrderId)->count());
    }

    public function test_cross_user_verification_is_rejected(): void
    {
        $owner = User::factory()->create();
        $order = Order::factory()->for($owner)->withStatus(OrderStatus::Pending)->create();
        $init = $this->actingAs($owner)->postJson("/api/v1/orders/{$order->reference}/payment");
        $gatewayOrderId = $init->json('data.gateway_order_id');

        $intruder = User::factory()->create();
        $paymentId = 'pay_intruder';
        $signature = FakePaymentGateway::sign($gatewayOrderId, $paymentId);

        $this->actingAs($intruder)->postJson('/api/v1/payments/verify', [
            'razorpay_order_id' => $gatewayOrderId,
            'razorpay_payment_id' => $paymentId,
            'razorpay_signature' => $signature,
        ])->assertNotFound();
    }

    public function test_unknown_gateway_order_id_returns_404(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/payments/verify', [
            'razorpay_order_id' => 'order_does_not_exist',
            'razorpay_payment_id' => 'pay_x',
            'razorpay_signature' => 'sig',
        ])->assertNotFound();
    }

    // -- Retry ---------------------------------------------------------

    public function test_failed_payment_does_not_corrupt_the_order_and_a_retry_succeeds(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->withStatus(OrderStatus::Pending)->create();

        // First attempt fails (bad signature).
        $firstInit = $this->actingAs($user)->postJson("/api/v1/orders/{$order->reference}/payment");
        $this->actingAs($user)->postJson('/api/v1/payments/verify', [
            'razorpay_order_id' => $firstInit->json('data.gateway_order_id'),
            'razorpay_payment_id' => 'pay_bad',
            'razorpay_signature' => 'invalid',
        ])->assertUnprocessable();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => OrderStatus::Pending->value]);

        // Retry: a new attempt against the same (still-pending) order.
        $secondInit = $this->actingAs($user)->postJson("/api/v1/orders/{$order->reference}/payment");
        $secondInit->assertCreated();
        $this->assertSame(2, $order->payments()->count());

        $gatewayOrderId = $secondInit->json('data.gateway_order_id');
        $paymentId = 'pay_retry_ok';
        $signature = FakePaymentGateway::sign($gatewayOrderId, $paymentId);

        $this->actingAs($user)->postJson('/api/v1/payments/verify', [
            'razorpay_order_id' => $gatewayOrderId,
            'razorpay_payment_id' => $paymentId,
            'razorpay_signature' => $signature,
        ])->assertOk()->assertJsonPath('data.status', 'paid');

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => OrderStatus::Paid->value]);
    }

    // -- Serialization ---------------------------------------------------------

    public function test_payment_resource_never_exposes_gateway_secrets_or_internals(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->withStatus(OrderStatus::Pending)->create();
        $init = $this->actingAs($user)->postJson("/api/v1/orders/{$order->reference}/payment");
        $gatewayOrderId = $init->json('data.gateway_order_id');
        $paymentId = 'pay_serialize';
        $signature = FakePaymentGateway::sign($gatewayOrderId, $paymentId);

        $response = $this->actingAs($user)->postJson('/api/v1/payments/verify', [
            'razorpay_order_id' => $gatewayOrderId,
            'razorpay_payment_id' => $paymentId,
            'razorpay_signature' => $signature,
        ]);

        $paymentKeys = array_keys($response->json('data.payment'));
        sort($paymentKeys);
        $this->assertSame(['amount', 'created_at', 'currency', 'failure_message', 'gateway', 'id', 'paid_at', 'status'], $paymentKeys);

        $body = $response->getContent();
        foreach ([$gatewayOrderId, $signature, 'gateway_signature', 'gateway_payment_id', 'metadata'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body);
        }
    }
}
