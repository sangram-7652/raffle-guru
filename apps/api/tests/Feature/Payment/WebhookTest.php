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

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(PaymentGatewayInterface::class, new FakePaymentGateway());
    }

    private function payloadFor(string $eventType, string $gatewayOrderId, string $gatewayPaymentId): string
    {
        return json_encode([
            'entity' => 'event',
            'event' => $eventType,
            'contains' => ['payment'],
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => $gatewayPaymentId,
                        'order_id' => $gatewayOrderId,
                        'amount' => 500,
                        'currency' => 'GBP',
                        'status' => $eventType === 'payment.captured' ? 'captured' : 'failed',
                        'error_code' => $eventType === 'payment.failed' ? 'GATEWAY_ERROR' : null,
                        'error_description' => $eventType === 'payment.failed' ? 'Card declined.' : null,
                    ],
                ],
            ],
            'created_at' => now()->timestamp,
        ]);
    }

    private function postWebhook(string $rawBody): \Illuminate\Testing\TestResponse
    {
        return $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
            ->call('POST', '/api/v1/payments/webhook/razorpay', [], [], [], [
                'HTTP_X_RAZORPAY_SIGNATURE' => FakePaymentGateway::sign($rawBody),
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ], $rawBody);
    }

    public function test_webhook_requires_no_customer_session(): void
    {
        $order = Order::factory()->withStatus(OrderStatus::Pending)->create();
        $payment = Payment::factory()->for($order)->create();
        $body = $this->payloadFor('payment.captured', $payment->gateway_order_id, 'pay_wh_1');

        // Deliberately no actingAs() — the webhook must work unauthenticated.
        $response = $this->postWebhook($body);

        $response->assertOk();
    }

    public function test_webhook_with_invalid_signature_is_rejected_and_not_processed(): void
    {
        $order = Order::factory()->withStatus(OrderStatus::Pending)->create();
        $payment = Payment::factory()->for($order)->create();
        $body = $this->payloadFor('payment.captured', $payment->gateway_order_id, 'pay_wh_2');

        $response = $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
            ->call('POST', '/api/v1/payments/webhook/razorpay', [], [], [], [
                'HTTP_X_RAZORPAY_SIGNATURE' => 'not-a-real-signature',
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ], $body);

        $response->assertStatus(400);
        $this->assertDatabaseCount('payment_webhooks', 0);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => PaymentStatus::Created->value]);
    }

    public function test_payment_captured_webhook_marks_order_paid(): void
    {
        $order = Order::factory()->withStatus(OrderStatus::PaymentPending)->create();
        $payment = Payment::factory()->for($order)->create();
        $body = $this->payloadFor('payment.captured', $payment->gateway_order_id, 'pay_wh_3');

        $this->postWebhook($body)->assertOk();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => OrderStatus::Paid->value]);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => PaymentStatus::Paid->value]);
    }

    public function test_payment_failed_webhook_marks_payment_failed_and_reverts_order_to_pending(): void
    {
        $order = Order::factory()->withStatus(OrderStatus::PaymentPending)->create();
        $payment = Payment::factory()->for($order)->create();
        $body = $this->payloadFor('payment.failed', $payment->gateway_order_id, 'pay_wh_4');

        $this->postWebhook($body)->assertOk();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => OrderStatus::Pending->value]);
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::Failed->value,
            'failure_code' => 'GATEWAY_ERROR',
        ]);
    }

    public function test_duplicate_webhook_delivery_is_processed_only_once(): void
    {
        $order = Order::factory()->withStatus(OrderStatus::Pending)->create();
        $payment = Payment::factory()->for($order)->create();
        $body = $this->payloadFor('payment.captured', $payment->gateway_order_id, 'pay_wh_5');

        $this->postWebhook($body)->assertOk();
        $this->postWebhook($body)->assertOk();

        $this->assertSame(1, \App\Models\PaymentWebhook::query()->count());
        $this->assertSame(1, Payment::query()->where('id', $payment->id)->where('status', PaymentStatus::Paid->value)->count());
    }

    public function test_webhook_for_an_unknown_gateway_order_id_is_acknowledged_without_error(): void
    {
        $body = $this->payloadFor('payment.captured', 'order_unknown_xyz', 'pay_wh_6');

        $this->postWebhook($body)->assertOk();
    }

    public function test_webhook_arriving_after_browser_verification_is_a_safe_no_op(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->withStatus(OrderStatus::Pending)->create();
        $init = $this->actingAs($user)->postJson("/api/v1/orders/{$order->reference}/payment");
        $gatewayOrderId = $init->json('data.gateway_order_id');
        $gatewayPaymentId = 'pay_race_1';
        $signature = FakePaymentGateway::sign($gatewayOrderId, $gatewayPaymentId);

        // Browser verification lands first.
        $this->actingAs($user)->postJson('/api/v1/payments/verify', [
            'razorpay_order_id' => $gatewayOrderId,
            'razorpay_payment_id' => $gatewayPaymentId,
            'razorpay_signature' => $signature,
        ])->assertOk();

        // The gateway's own webhook for the same event arrives afterwards.
        $body = $this->payloadFor('payment.captured', $gatewayOrderId, $gatewayPaymentId);
        $this->postWebhook($body)->assertOk();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => OrderStatus::Paid->value]);
        $this->assertSame(1, Payment::query()->where('gateway_order_id', $gatewayOrderId)->where('status', PaymentStatus::Paid->value)->count());
    }

    public function test_unrecognized_event_type_is_acknowledged_and_ignored(): void
    {
        $body = json_encode(['entity' => 'event', 'event' => 'order.paid', 'payload' => [], 'created_at' => now()->timestamp]);

        $this->postWebhook($body)->assertOk();
    }
}
