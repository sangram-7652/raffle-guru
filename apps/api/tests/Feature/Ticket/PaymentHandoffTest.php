<?php

namespace Tests\Feature\Ticket;

use App\Enums\OrderStatus;
use App\Jobs\AllocateTicketsForOrder;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\PaymentService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * PaymentService::markPaid() fires App\Events\OrderPaid via
 * DB::afterCommit() (see docs/TICKET_ENGINE_DESIGN_P7.md "Order/payment
 * handoff") — that closure only ever runs once its transaction has
 * genuinely, durably committed. The default RefreshDatabase trait wraps
 * every test in a transaction that's always rolled back, so afterCommit
 * callbacks never fire under it — this file deliberately uses
 * DatabaseMigrations instead (migrates fresh per test, no wrapping
 * transaction, real commits) so this specific behavior can be tested
 * honestly rather than skipped.
 */
class PaymentHandoffTest extends TestCase
{
    use DatabaseMigrations;

    public function test_marking_a_payment_paid_dispatches_ticket_allocation_exactly_once(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $order = Order::factory()->for($user)->withStatus(OrderStatus::Pending)->create();
        $payment = Payment::factory()->for($order)->create();

        app(PaymentService::class)->markPaid($payment);

        Queue::assertPushed(AllocateTicketsForOrder::class, 1);
    }

    public function test_an_already_paid_payment_does_not_dispatch_allocation_again(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $order = Order::factory()->for($user)->withStatus(OrderStatus::Paid)->create();
        $payment = Payment::factory()->for($order)->paid()->create();

        app(PaymentService::class)->markPaid($payment);

        Queue::assertNotPushed(AllocateTicketsForOrder::class);
    }

    public function test_webhook_driven_payment_confirmation_also_dispatches_allocation(): void
    {
        // The webhook controller calls the exact same PaymentService —
        // no P7-specific code was added to it (see
        // docs/TICKET_ENGINE_DESIGN_P7.md "Order/payment handoff").
        // Confirmed here by exercising markPaid() the same way
        // PaymentWebhookController does, not by adding anything new to
        // that controller.
        Queue::fake();

        $user = User::factory()->create();
        $order = Order::factory()->for($user)->withStatus(OrderStatus::PaymentPending)->create();
        $payment = Payment::factory()->for($order)->create();

        app(PaymentService::class)->markPaid($payment, ['gateway_signature' => 'sig']);

        Queue::assertPushed(AllocateTicketsForOrder::class, function (AllocateTicketsForOrder $job) use ($order) {
            return $job->order->is($order);
        });
    }
}
