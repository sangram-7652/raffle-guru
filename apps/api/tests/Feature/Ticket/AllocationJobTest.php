<?php

namespace Tests\Feature\Ticket;

use App\Enums\CompetitionStatus;
use App\Enums\OrderStatus;
use App\Jobs\AllocateTicketsForOrder;
use App\Models\Competition;
use App\Models\Entry;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Ticket;
use App\Services\Tickets\TicketAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AllocationJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_job_allocates_a_payable_order(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create(['max_entries' => 1000]);
        $order = Order::factory()->withStatus(OrderStatus::Paid)->create();
        OrderItem::factory()->for($order)->for($competition)->create(['quantity' => 2]);

        (new AllocateTicketsForOrder($order))->handle(app(TicketAllocationService::class));

        $this->assertSame(1, Entry::query()->count());
        $this->assertSame(2, Ticket::query()->count());
    }

    public function test_a_capacity_conflict_fails_the_job_without_throwing(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create(['max_entries' => 2]);
        $blockingOrder = Order::factory()->withStatus(OrderStatus::Paid)->create();
        OrderItem::factory()->for($blockingOrder)->for($competition)->create(['quantity' => 2]);
        (new AllocateTicketsForOrder($blockingOrder))->handle(app(TicketAllocationService::class));

        $order = Order::factory()->withStatus(OrderStatus::Paid)->create();
        OrderItem::factory()->for($order)->for($competition)->create(['quantity' => 2]);

        // The job must catch the deterministic allocation failure itself
        // (calling $this->fail() internally) rather than letting it
        // propagate — a real queue worker would otherwise burn through
        // retry attempts for an outcome that can never change. See
        // docs/TICKET_ENGINE_DESIGN_P7.md "Queue/job behavior".
        (new AllocateTicketsForOrder($order))->handle(app(TicketAllocationService::class));

        $this->assertSame(0, Entry::query()->where('order_id', $order->id)->count());
        // The order stays paid — a ticket-side failure never touches it.
        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
    }
}
