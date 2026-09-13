<?php

namespace App\Services\Payments;

use App\Models\Payment;

/**
 * Deterministic, network-free stand-in for RazorpayPaymentGateway — used
 * by automated tests, and by local/Docker manual verification when no
 * real Razorpay account is configured (PAYMENT_GATEWAY=fake). See
 * docs/PAYMENTS_DESIGN_P6.md "Test gateway" for the exact conventions
 * below, which exist so a caller across *separate* HTTP requests (no
 * shared PHP memory) can still deterministically choose the outcome.
 */
class FakePaymentGateway implements PaymentGatewayInterface
{
    /** Not a real secret — this class never talks to a network. */
    public const SECRET = 'fake-gateway-secret';

    public function name(): string
    {
        return 'razorpay';
    }

    public function createOrder(int $amountMinorUnits, string $currency, string $receipt): array
    {
        return [
            'id' => 'order_fake_'.bin2hex(random_bytes(10)),
            'amount' => $amountMinorUnits,
            'currency' => $currency,
        ];
    }

    public function verifyPaymentSignature(string $gatewayOrderId, string $gatewayPaymentId, string $signature): bool
    {
        return hash_equals(self::sign($gatewayOrderId, $gatewayPaymentId), $signature);
    }

    /**
     * Simulates the gateway's own server-side record for a payment id by
     * reading this application's own `payments` table — the only
     * practical way for a stateless fake to "remember" what an earlier,
     * separate HTTP request (createOrder/verify) already told it,
     * without a real external service. Real gateways are, of course,
     * genuinely independent of our database.
     */
    public function fetchPayment(string $gatewayPaymentId): array
    {
        if (str_starts_with($gatewayPaymentId, 'pay_fail_')) {
            return [
                'id' => $gatewayPaymentId,
                'order_id' => '',
                'amount' => 0,
                'currency' => '',
                'status' => 'failed',
                'failure_code' => 'BAD_REQUEST_ERROR',
                'failure_message' => 'Simulated gateway failure (fake gateway).',
            ];
        }

        $payment = Payment::query()
            ->where('gateway_payment_id', $gatewayPaymentId)
            ->first();

        if (! $payment) {
            return [
                'id' => $gatewayPaymentId,
                'order_id' => '',
                'amount' => 0,
                'currency' => '',
                'status' => 'failed',
                'failure_code' => 'BAD_REQUEST_ERROR',
                'failure_message' => 'No such payment (fake gateway).',
            ];
        }

        // Deliberately wrong amount, to exercise the tamper-rejection
        // path in PaymentController::verify() without needing a second
        // real gateway to disagree with us.
        $amount = str_starts_with($gatewayPaymentId, 'pay_mismatch_')
            ? $payment->amount + 1
            : $payment->amount;

        return [
            'id' => $gatewayPaymentId,
            'order_id' => $payment->gateway_order_id,
            'amount' => $amount,
            'currency' => $payment->currency,
            'status' => 'captured',
            'failure_code' => null,
            'failure_message' => null,
        ];
    }

    public function verifyWebhookSignature(string $rawBody, string $signature): bool
    {
        return hash_equals(self::sign($rawBody), $signature);
    }

    /**
     * Public so tests and manual curl verification can compute a valid
     * signature for a chosen (order id, payment id) pair or raw webhook
     * body — mirrors the shape of Razorpay's own HMAC-SHA256 signing.
     */
    public static function sign(string ...$parts): string
    {
        return hash_hmac('sha256', implode('|', $parts), self::SECRET);
    }
}
