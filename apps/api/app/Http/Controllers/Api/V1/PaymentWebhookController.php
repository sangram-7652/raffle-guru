<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentWebhook;
use App\Services\Payments\PaymentGatewayInterface;
use App\Services\Payments\PaymentService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Not behind auth:sanctum — authenticity comes entirely from the
 * webhook signature, not a customer session. See
 * docs/PAYMENTS_DESIGN_P6.md "Webhook flow" / "Idempotency strategy".
 */
class PaymentWebhookController extends Controller
{
    public function __construct(
        private readonly PaymentGatewayInterface $gateway,
        private readonly PaymentService $payments,
    ) {}

    public function razorpay(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();
        $signature = (string) $request->header('X-Razorpay-Signature');

        if ($signature === '' || ! $this->gateway->verifyWebhookSignature($rawBody, $signature)) {
            Log::warning('payment.webhook.invalid_signature', ['gateway' => 'razorpay']);

            return response()->json(['message' => 'Invalid signature.'], 400);
        }

        $payload = json_decode($rawBody, true) ?? [];
        $eventType = (string) ($payload['event'] ?? 'unknown');

        // Deterministic dedupe key — see docs/PAYMENTS_DESIGN_P6.md
        // "Idempotency strategy" for why this, not an assumed gateway
        // event id.
        $eventId = hash('sha256', $rawBody);

        // Refund entities carry `payment_id`, not `order_id` — normalize
        // so the rest of this handler can always key off `order_id` when
        // looking up which of our Payment rows an event concerns.
        $paymentEntity = $payload['payload']['payment']['entity'] ?? null;
        $refundEntity = $payload['payload']['refund']['entity'] ?? null;
        $entity = $paymentEntity ?? $refundEntity ?? [];

        try {
            $webhook = PaymentWebhook::query()->create([
                'gateway' => 'razorpay',
                'event_id' => $eventId,
                'event_type' => $eventType,
                // Curated, non-sensitive subset only — never the full
                // raw payload.
                'metadata' => [
                    'gateway_order_id' => $entity['order_id'] ?? null,
                    'gateway_payment_id' => $entity['id'] ?? null,
                    'amount' => $entity['amount'] ?? null,
                    'currency' => $entity['currency'] ?? null,
                    'status' => $entity['status'] ?? null,
                    'method' => $entity['method'] ?? null,
                ],
            ]);
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'payment_webhooks_gateway_event_id_unique')) {
                // Already processed this exact delivery — acknowledge
                // without reprocessing.
                return response()->json(['status' => 'ok']);
            }

            throw $e;
        }

        $this->process($eventType, $entity);

        $webhook->update(['processed_at' => now()]);

        return response()->json(['status' => 'ok']);
    }

    /**
     * @param  array<string, mixed>  $entity
     */
    private function process(string $eventType, array $entity): void
    {
        if ($eventType === 'payment.captured') {
            $payment = Payment::query()
                ->where('gateway', 'razorpay')
                ->where('gateway_order_id', $entity['order_id'] ?? null)
                ->first();

            if ($payment) {
                $payment->update(['gateway_payment_id' => $entity['id'] ?? $payment->gateway_payment_id]);
                $this->payments->markPaid($payment);
            }

            return;
        }

        if ($eventType === 'payment.failed') {
            $payment = Payment::query()
                ->where('gateway', 'razorpay')
                ->where('gateway_order_id', $entity['order_id'] ?? null)
                ->first();

            if ($payment) {
                $this->payments->markFailed($payment, [
                    'failure_code' => $entity['error_code'] ?? null,
                    'failure_message' => $entity['error_description'] ?? 'Payment failed.',
                ]);
            }

            return;
        }

        if (str_contains($eventType, 'refund')) {
            // A refund entity carries `payment_id`, not an order id — see
            // the normalization note where $entity is built.
            $payment = Payment::query()
                ->where('gateway', 'razorpay')
                ->where(function ($query) use ($entity) {
                    $query->where('gateway_order_id', $entity['order_id'] ?? null)
                        ->orWhere('gateway_payment_id', $entity['payment_id'] ?? null);
                })
                ->first();

            if ($payment) {
                $this->payments->markRefunded($payment);
            }

            return;
        }

        // Every other event type (order.paid, payment.authorized, ...)
        // is acknowledged and ignored — this application has no reason
        // to act on it, and a non-2xx would just cause pointless
        // gateway retries.
        Log::info('payment.webhook.ignored', ['event_type' => $eventType]);
    }
}
