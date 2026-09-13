<?php

namespace App\Services\Payments;

use Razorpay\Api\Api;
use Razorpay\Api\Errors\SignatureVerificationError;

/**
 * Thin wrapper over the official razorpay/razorpay Composer SDK. Nothing
 * outside this class (and its interface) ever touches the SDK or the
 * account's key secret directly — see docs/PAYMENTS_DESIGN_P6.md.
 */
class RazorpayPaymentGateway implements PaymentGatewayInterface
{
    private Api $api;

    public function __construct(
        string $keyId,
        private readonly string $keySecret,
        private readonly string $webhookSecret,
    ) {
        $this->api = new Api($keyId, $keySecret);
    }

    public function name(): string
    {
        return 'razorpay';
    }

    public function createOrder(int $amountMinorUnits, string $currency, string $receipt): array
    {
        $order = $this->api->order->create([
            'amount' => $amountMinorUnits,
            'currency' => $currency,
            'receipt' => $receipt,
        ]);

        return [
            'id' => $order->id,
            'amount' => (int) $order->amount,
            'currency' => $order->currency,
        ];
    }

    public function verifyPaymentSignature(string $gatewayOrderId, string $gatewayPaymentId, string $signature): bool
    {
        try {
            $this->api->utility->verifyPaymentSignature([
                'razorpay_order_id' => $gatewayOrderId,
                'razorpay_payment_id' => $gatewayPaymentId,
                'razorpay_signature' => $signature,
            ]);

            return true;
        } catch (SignatureVerificationError) {
            return false;
        }
    }

    public function fetchPayment(string $gatewayPaymentId): array
    {
        $payment = $this->api->payment->fetch($gatewayPaymentId)->toArray();

        return [
            'id' => $payment['id'],
            'order_id' => $payment['order_id'],
            'amount' => (int) $payment['amount'],
            'currency' => $payment['currency'],
            'status' => $payment['status'],
            'failure_code' => $payment['error_code'] ?? null,
            'failure_message' => $payment['error_description'] ?? null,
        ];
    }

    public function verifyWebhookSignature(string $rawBody, string $signature): bool
    {
        try {
            $this->api->utility->verifyWebhookSignature($rawBody, $signature, $this->webhookSecret);

            return true;
        } catch (SignatureVerificationError) {
            return false;
        }
    }
}
