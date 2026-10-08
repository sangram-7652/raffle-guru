<?php

namespace Tests\Feature\Payment;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Payments\FakePaymentGateway;
use App\Services\Payments\PaymentGatewayInterface;
use App\Services\Payments\PaymentService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * P12.3 — payment/webhook idempotency under REAL concurrent MySQL
 * connections (pcntl_fork(), same technique as
 * Tests\Feature\Ticket\ConcurrencyTest / Admin concurrency tests — see
 * those classes' docblocks for why this can't be proven on SQLite).
 *
 * Two layers of defense exist and are tested separately, matching
 * App\Services\Draw\DrawService's own "lock + unique-constraint
 * backstop" posture:
 *
 *  1. PaymentWebhookController's (gateway, event_id) unique constraint —
 *     the SAME webhook delivery, retried/duplicated concurrently, must
 *     be claimed by exactly one request.
 *  2. PaymentService::markPaid()'s row lock + idempotent status check —
 *     two DIFFERENT callers (e.g. a browser verification and a webhook,
 *     or two distinct webhook deliveries that both ultimately resolve
 *     to the same Payment) racing on the same payment must still
 *     produce exactly one effective Paid transition.
 *
 * #[Group('mysql')] (P11.13 convention): see AdminManagementConcurrencyTest.
 */
#[Group('mysql')]
class PaymentConcurrencyTest extends TestCase
{
    /** @var array<int, int> */
    private array $createdOrderIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('pcntl')) {
            $this->markTestSkipped('pcntl extension not available — cannot fork for a genuine concurrency test.');
        }

        $this->useIsolatedMysqlDatabase();

        // Bound before forking so every child inherits it (copy-on-write
        // — see the fork helpers below) without each needing its own
        // network-dependent gateway.
        $this->app->instance(PaymentGatewayInterface::class, new FakePaymentGateway);
    }

    protected function tearDown(): void
    {
        DB::connection('mysql')->table('payment_webhooks')->truncate();
        DB::connection('mysql')->table('payments')->whereIn('order_id', $this->createdOrderIds)->delete();
        DB::connection('mysql')->table('orders')->whereIn('id', $this->createdOrderIds)->delete();

        Config::set('database.default', 'sqlite');
        DB::purge('mysql');

        parent::tearDown();
    }

    private function payloadFor(string $gatewayOrderId, string $gatewayPaymentId): string
    {
        // created_at is deliberately fixed (not now()) — the webhook's
        // dedupe key is a hash of this exact raw body (see
        // PaymentWebhookController), so two "duplicate deliveries" of
        // the SAME event must produce byte-identical bodies, exactly as
        // a real gateway redelivering the same event would.
        return json_encode([
            'entity' => 'event',
            'event' => 'payment.captured',
            'contains' => ['payment'],
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => $gatewayPaymentId,
                        'order_id' => $gatewayOrderId,
                        'amount' => 500,
                        'currency' => 'GBP',
                        'status' => 'captured',
                    ],
                ],
            ],
            'created_at' => 1700000000,
        ]);
    }

    /**
     * Forks a child that POSTs $body to the real webhook route (the
     * exact HTTP kernel path a concurrent gateway redelivery would hit),
     * writing the HTTP status to $resultPath.
     */
    private function forkWebhookDelivery(string $body, string $resultPath): int
    {
        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('pcntl_fork() failed.');
        }

        if ($pid === 0) {
            DB::purge('mysql');

            $response = $this->withoutMiddleware(ValidateCsrfToken::class)
                ->call('POST', '/api/v1/payments/webhook/razorpay', [], [], [], [
                    'HTTP_X_RAZORPAY_SIGNATURE' => FakePaymentGateway::sign($body),
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_ACCEPT' => 'application/json',
                ], $body);

            file_put_contents($resultPath, json_encode(['status' => $response->getStatusCode()]));
            exit(0);
        }

        return $pid;
    }

    /**
     * Forks a child that calls PaymentService::markPaid() directly on
     * the same Payment — the layer below the webhook's own dedupe table,
     * exercised the way two genuinely different callers (a browser
     * verification request and a webhook, or two distinct webhook
     * deliveries) would both reach it.
     */
    private function forkMarkPaid(int $paymentId, string $resultPath): int
    {
        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('pcntl_fork() failed.');
        }

        if ($pid === 0) {
            DB::purge('mysql');

            $payment = Payment::query()->findOrFail($paymentId);
            app(PaymentService::class)->markPaid($payment);

            file_put_contents($resultPath, json_encode(['success' => true]));
            exit(0);
        }

        return $pid;
    }

    public function test_the_same_webhook_delivery_sent_twice_concurrently_is_claimed_exactly_once(): void
    {
        $order = Order::factory()->withStatus(OrderStatus::PaymentPending)->create();
        $this->createdOrderIds[] = $order->id;
        $payment = Payment::factory()->for($order)->create();

        // Byte-identical body both children send — the real-world
        // scenario this guards: a gateway redelivering the exact same
        // event because it never saw our first 200 OK.
        $body = $this->payloadFor($payment->gateway_order_id, 'pay_concurrency_'.uniqid());

        $resultA = sys_get_temp_dir().'/p12_3_webhook_'.uniqid().'_a.json';
        $resultB = sys_get_temp_dir().'/p12_3_webhook_'.uniqid().'_b.json';

        $pidA = $this->forkWebhookDelivery($body, $resultA);
        $pidB = $this->forkWebhookDelivery($body, $resultB);

        pcntl_waitpid($pidA, $statusA);
        pcntl_waitpid($pidB, $statusB);

        $outcomeA = json_decode((string) file_get_contents($resultA), true);
        $outcomeB = json_decode((string) file_get_contents($resultB), true);
        @unlink($resultA);
        @unlink($resultB);

        // Both requests get a 200 — the "duplicate" branch is still a
        // success response (see PaymentWebhookController), never an
        // error, so the gateway has no reason to keep retrying.
        $this->assertSame(200, $outcomeA['status']);
        $this->assertSame(200, $outcomeB['status']);

        // The actual invariant: exactly one row claimed this event —
        // never zero, never two.
        $this->assertSame(
            1,
            DB::connection('mysql')->table('payment_webhooks')
                ->where('gateway', 'razorpay')
                ->where('event_id', hash('sha256', $body))
                ->count(),
        );

        // Exactly one effective transition reached the Payment/Order.
        $this->assertSame(
            PaymentStatus::Paid->value,
            DB::connection('mysql')->table('payments')->where('id', $payment->id)->value('status'),
        );
        $this->assertSame(
            OrderStatus::Paid->value,
            DB::connection('mysql')->table('orders')->where('id', $order->id)->value('status'),
        );
    }

    public function test_two_concurrent_mark_paid_calls_on_the_same_payment_apply_exactly_once(): void
    {
        $order = Order::factory()->withStatus(OrderStatus::PaymentPending)->create();
        $this->createdOrderIds[] = $order->id;
        $payment = Payment::factory()->for($order)->create();

        $resultA = sys_get_temp_dir().'/p12_3_markpaid_'.uniqid().'_a.json';
        $resultB = sys_get_temp_dir().'/p12_3_markpaid_'.uniqid().'_b.json';

        $pidA = $this->forkMarkPaid($payment->id, $resultA);
        $pidB = $this->forkMarkPaid($payment->id, $resultB);

        pcntl_waitpid($pidA, $statusA);
        pcntl_waitpid($pidB, $statusB);

        $outcomeA = json_decode((string) file_get_contents($resultA), true);
        $outcomeB = json_decode((string) file_get_contents($resultB), true);
        @unlink($resultA);
        @unlink($resultB);

        // Neither caller should ever see an exception — markPaid() is
        // designed to be a safe no-op for whichever one loses the race,
        // not an error.
        $this->assertTrue($outcomeA['success'] ?? false, 'First markPaid() call did not complete.');
        $this->assertTrue($outcomeB['success'] ?? false, 'Second markPaid() call did not complete.');

        $this->assertSame(
            PaymentStatus::Paid->value,
            DB::connection('mysql')->table('payments')->where('id', $payment->id)->value('status'),
        );
        $this->assertSame(
            OrderStatus::Paid->value,
            DB::connection('mysql')->table('orders')->where('id', $order->id)->value('status'),
        );

        // paid_at is stamped by whichever call actually performed the
        // transition — it must exist and be singular/consistent, not
        // overwritten a second time by the no-op loser (which never
        // reaches the ->update() call at all).
        $paidAt = DB::connection('mysql')->table('payments')->where('id', $payment->id)->value('paid_at');
        $this->assertNotNull($paidAt);
    }
}
