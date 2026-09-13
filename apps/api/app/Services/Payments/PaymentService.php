<?php

namespace App\Services\Payments;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\OrderPaid;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * The only place a Payment/Order pair is transitioned. Both
 * PaymentController::verify() (browser) and PaymentWebhookController
 * (gateway) call into this, so "transition exactly once, under a lock"
 * exists in exactly one place. See docs/PAYMENTS_DESIGN_P6.md.
 */
class PaymentService
{
    /**
     * @param  array{failure_code?: string|null, failure_message?: string|null}  $gatewayData
     */
    public function markPaid(Payment $payment, array $gatewayData = []): Payment
    {
        return DB::transaction(function () use ($payment, $gatewayData) {
            /** @var Payment $locked */
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            /** @var Order $order */
            $order = Order::query()->lockForUpdate()->findOrFail($locked->order_id);

            // Idempotent no-op — whichever request (browser verification
            // or webhook) acquires the lock first wins; the other finds
            // this already true and changes nothing.
            if ($locked->status === PaymentStatus::Paid) {
                return $locked;
            }

            $locked->update([
                'status' => PaymentStatus::Paid,
                'paid_at' => now(),
                'gateway_signature' => $gatewayData['gateway_signature'] ?? $locked->gateway_signature,
            ]);

            if ($order->isPayable()) {
                $order->update(['status' => OrderStatus::Paid]);

                // P7 handoff — fired only on the call that actually
                // performs this transition (never on the already-paid
                // no-op above), and only once the transition has
                // genuinely, durably committed. See
                // docs/TICKET_ENGINE_DESIGN_P7.md "Order/payment handoff".
                DB::afterCommit(fn () => OrderPaid::dispatch($order->fresh()));
            }

            return $locked;
        });
    }

    /**
     * @param  array{failure_code?: string|null, failure_message?: string|null}  $gatewayData
     */
    public function markFailed(Payment $payment, array $gatewayData = []): Payment
    {
        return DB::transaction(function () use ($payment, $gatewayData) {
            /** @var Payment $locked */
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            /** @var Order $order */
            $order = Order::query()->lockForUpdate()->findOrFail($locked->order_id);

            // A later attempt already succeeded (or this one already
            // failed) — never overwrite a settled outcome.
            if ($locked->status !== PaymentStatus::Created) {
                return $locked;
            }

            $locked->update([
                'status' => PaymentStatus::Failed,
                'failure_code' => $gatewayData['failure_code'] ?? null,
                'failure_message' => $gatewayData['failure_message'] ?? 'Payment could not be verified.',
            ]);

            // Never cancel/fail the order itself over one failed attempt
            // — it stays payable so the customer can retry. Only revert
            // the "attempt in flight" signal, and only if nothing else
            // (a concurrent attempt) already moved it on.
            if ($order->status === OrderStatus::PaymentPending) {
                $order->update(['status' => OrderStatus::Pending]);
            }

            return $locked;
        });
    }

    /**
     * Passive, audit-only: reflects a gateway-reported refund (typically
     * initiated outside this application). Does not initiate refunds —
     * see docs/PAYMENTS_DESIGN_P6.md "Refund boundary".
     */
    public function markRefunded(Payment $payment): Payment
    {
        return DB::transaction(function () use ($payment) {
            /** @var Payment $locked */
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            /** @var Order $order */
            $order = Order::query()->lockForUpdate()->findOrFail($locked->order_id);

            if ($locked->status === PaymentStatus::Refunded) {
                return $locked;
            }

            $locked->update(['status' => PaymentStatus::Refunded]);

            if ($order->status === OrderStatus::Paid) {
                $order->update(['status' => OrderStatus::Refunded]);
            }

            return $locked;
        });
    }
}
