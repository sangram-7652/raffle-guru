<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\VerifyPaymentRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Payments\PaymentGatewayInterface;
use App\Services\Payments\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentGatewayInterface $gateway,
        private readonly PaymentService $payments,
    ) {}

    /**
     * POST /v1/orders/{reference}/payment — start a payment attempt for
     * an existing, still-payable P5 order. See
     * docs/PAYMENTS_DESIGN_P6.md "Gateway order creation / initiation
     * flow". Never creates an order — P5 already did that.
     */
    public function store(Request $request, string $reference): JsonResponse
    {
        $order = $request->user()
            ->orders()
            ->where('reference', $reference)
            ->firstOrFail();

        if (! $order->isPayable()) {
            throw ValidationException::withMessages([
                'order' => ['This order cannot be paid — it is already paid, cancelled, or otherwise settled.'],
            ]);
        }

        // Amount/currency come only from the persisted order — see
        // docs/PAYMENTS_DESIGN_P6.md "Security model".
        $gatewayOrder = $this->gateway->createOrder($order->total, $order->currency, $order->reference);

        $payment = $order->payments()->create([
            'gateway' => $this->gateway->name(),
            'gateway_order_id' => $gatewayOrder['id'],
            'amount' => $order->total,
            'currency' => $order->currency,
            'status' => PaymentStatus::Created,
        ]);

        return response()->json([
            'data' => [
                'key_id' => (string) config('services.razorpay.key_id'),
                'gateway_order_id' => $payment->gateway_order_id,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'reference' => $order->reference,
                'name' => (string) config('app.name'),
            ],
        ], 201);
    }

    /**
     * POST /v1/payments/verify — called from the browser's Razorpay
     * Checkout success handler. Never trusts the browser's claim of
     * success by itself. See docs/PAYMENTS_DESIGN_P6.md "Verification
     * flow".
     */
    public function verify(VerifyPaymentRequest $request): OrderResource
    {
        $gatewayOrderId = $request->validated('razorpay_order_id');
        $gatewayPaymentId = $request->validated('razorpay_payment_id');
        $signature = $request->validated('razorpay_signature');

        $payment = Payment::query()
            ->where('gateway', $this->gateway->name())
            ->where('gateway_order_id', $gatewayOrderId)
            ->firstOrFail();

        if ($payment->order->user_id !== $request->user()->id) {
            abort(404);
        }

        if ($payment->status === PaymentStatus::Paid) {
            // Duplicate verification call — safe no-op, not an error.
            return new OrderResource($payment->order->fresh(['items', 'latestPayment', 'entries.competition', 'entries.tickets', 'entries.order']));
        }

        if (! $this->gateway->verifyPaymentSignature($gatewayOrderId, $gatewayPaymentId, $signature)) {
            $this->payments->markFailed($payment, [
                'failure_message' => 'Payment verification failed.',
            ]);

            throw ValidationException::withMessages([
                'payment' => ['Payment could not be verified.'],
            ]);
        }

        // The id pairing is now cryptographically proven — safe to
        // persist before the independent amount/currency check below.
        $payment->update(['gateway_payment_id' => $gatewayPaymentId, 'gateway_signature' => $signature]);

        $fetched = $this->gateway->fetchPayment($gatewayPaymentId);

        $verified = $fetched['order_id'] === $gatewayOrderId
            && $fetched['amount'] === $payment->amount
            && $fetched['currency'] === $payment->currency
            && $fetched['status'] === 'captured';

        if (! $verified) {
            $this->payments->markFailed($payment, [
                'failure_code' => $fetched['failure_code'] ?? null,
                'failure_message' => $fetched['failure_message'] ?? 'Payment could not be verified.',
            ]);

            throw ValidationException::withMessages([
                'payment' => ['Payment could not be verified.'],
            ]);
        }

        $this->payments->markPaid($payment, ['gateway_signature' => $signature]);

        return new OrderResource($payment->order->fresh(['items', 'latestPayment', 'entries.competition', 'entries.tickets', 'entries.order']));
    }
}
