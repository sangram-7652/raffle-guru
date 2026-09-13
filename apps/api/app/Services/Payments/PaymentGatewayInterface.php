<?php

namespace App\Services\Payments;

/**
 * Keeps every gateway-specific detail (SDK calls, signature algorithms,
 * credential handling) behind one boundary — PaymentController and
 * PaymentService only ever depend on this interface, never on
 * Razorpay/Requests/etc. directly. See docs/PAYMENTS_DESIGN_P6.md.
 */
interface PaymentGatewayInterface
{
    /**
     * Short identifier stored on Payment.gateway (e.g. "razorpay").
     */
    public function name(): string;

    /**
     * Create an order at the gateway for an amount this application has
     * already resolved server-side. Never accepts anything client-supplied.
     *
     * @return array{id: string, amount: int, currency: string}
     */
    public function createOrder(int $amountMinorUnits, string $currency, string $receipt): array;

    /**
     * Cryptographically verify that $gatewayPaymentId was genuinely
     * issued by the gateway for $gatewayOrderId — proves the id pairing,
     * not the amount (see fetchPayment()).
     */
    public function verifyPaymentSignature(string $gatewayOrderId, string $gatewayPaymentId, string $signature): bool;

    /**
     * Ask the gateway itself (never the browser) what it has on file for
     * a payment id — the independent source of truth for amount/
     * currency/status used to cross-check what this application stored.
     *
     * @return array{id: string, order_id: string, amount: int, currency: string, status: string, failure_code?: string|null, failure_message?: string|null}
     */
    public function fetchPayment(string $gatewayPaymentId): array;

    /**
     * Verify a webhook delivery's signature over the exact raw request
     * body. Must be checked before the body is parsed or persisted.
     */
    public function verifyWebhookSignature(string $rawBody, string $signature): bool;
}
