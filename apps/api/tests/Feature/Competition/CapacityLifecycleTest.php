<?php

namespace Tests\Feature\Competition;

use App\Enums\CompetitionStatus;
use App\Enums\OrderStatus;
use App\Models\Competition;
use App\Models\CompetitionLifecycleEvent;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Ticket;
use App\Services\Tickets\TicketAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Capacity-driven active -> sold_out is triggered inline by
 * TicketAllocationService (P7), not by the lifecycle command — see
 * docs/COMPETITION_LIFECYCLE_P8.md. These tests exercise it through the
 * real allocation path, not by calling the lifecycle service directly,
 * so they also double as regression coverage that P7 still behaves
 * correctly with the P8 dependency wired in.
 */
class CapacityLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function paidOrderWithItem(Competition $competition, int $quantity): Order
    {
        $order = Order::factory()->withStatus(OrderStatus::Paid)->create();
        OrderItem::factory()->for($order)->for($competition)->create(['quantity' => $quantity]);

        return $order;
    }

    public function test_active_competition_below_capacity_remains_active(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create(['max_entries' => 10]);
        $order = $this->paidOrderWithItem($competition, 4);

        app(TicketAllocationService::class)->allocateOrder($order);

        $this->assertSame(CompetitionStatus::Active, $competition->fresh()->status);
    }

    public function test_competition_reaching_exact_capacity_becomes_sold_out(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create(['max_entries' => 5]);
        $order = $this->paidOrderWithItem($competition, 5);

        app(TicketAllocationService::class)->allocateOrder($order);

        $this->assertSame(CompetitionStatus::SoldOut, $competition->fresh()->status);
        $this->assertSame(5, Ticket::query()->where('competition_id', $competition->id)->count());
    }

    public function test_reaching_capacity_writes_a_lifecycle_audit_event(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create(['max_entries' => 3]);
        $order = $this->paidOrderWithItem($competition, 3);

        app(TicketAllocationService::class)->allocateOrder($order);

        $event = CompetitionLifecycleEvent::query()->where('competition_id', $competition->id)->first();
        $this->assertNotNull($event);
        $this->assertSame('active', $event->from_status);
        $this->assertSame('sold_out', $event->to_status);
        $this->assertSame('capacity reached', $event->reason);
    }

    public function test_capacity_across_multiple_orders_reaching_the_cap_becomes_sold_out(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create(['max_entries' => 5]);
        app(TicketAllocationService::class)->allocateOrder($this->paidOrderWithItem($competition, 3));
        $this->assertSame(CompetitionStatus::Active, $competition->fresh()->status);

        app(TicketAllocationService::class)->allocateOrder($this->paidOrderWithItem($competition, 2));

        $this->assertSame(CompetitionStatus::SoldOut, $competition->fresh()->status);
    }

    public function test_no_further_allocation_succeeds_once_sold_out(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create(['max_entries' => 5]);
        app(TicketAllocationService::class)->allocateOrder($this->paidOrderWithItem($competition, 5));
        $this->assertSame(CompetitionStatus::SoldOut, $competition->fresh()->status);

        $secondOrder = $this->paidOrderWithItem($competition, 1);

        $this->expectException(\App\Services\Tickets\CapacityExceededException::class);
        app(TicketAllocationService::class)->allocateOrder($secondOrder);
    }

    public function test_capacity_decision_is_derived_only_from_the_tickets_table_no_second_counter(): void
    {
        // There is no competitions.tickets_sold (or similar) column to
        // even set — this test documents/asserts that fact structurally
        // by confirming the only way tickets are counted is via the
        // tickets table itself, already exercised above. Directly
        // assert the column doesn't exist as a guard against a future
        // regression reintroducing a second counter.
        $columns = \Illuminate\Support\Facades\Schema::getColumnListing('competitions');

        $this->assertNotContains('tickets_sold', $columns);
        $this->assertNotContains('entries_count', $columns);
        $this->assertNotContains('sold_count', $columns);
    }
}
