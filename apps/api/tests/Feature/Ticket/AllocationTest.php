<?php

namespace Tests\Feature\Ticket;

use App\Enums\CompetitionStatus;
use App\Enums\OrderStatus;
use App\Models\Competition;
use App\Models\Entry;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tickets\CapacityExceededException;
use App\Services\Tickets\PerUserLimitExceededException;
use App\Services\Tickets\TicketAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AllocationTest extends TestCase
{
    use RefreshDatabase;

    private function service(): TicketAllocationService
    {
        return app(TicketAllocationService::class);
    }

    private function paidOrderWithItem(Competition $competition, int $quantity, ?User $user = null): Order
    {
        $user ??= User::factory()->create();
        $order = Order::factory()->for($user)->withStatus(OrderStatus::Paid)->create();
        OrderItem::factory()->for($order)->for($competition)->create(['quantity' => $quantity]);

        return $order;
    }

    // -- Basic allocation --------------------------------------------------

    public function test_allocating_a_paid_order_creates_an_entry_and_tickets(): void
    {
        $user = User::factory()->create();
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create(['max_entries' => 1000]);
        $order = $this->paidOrderWithItem($competition, 3, $user);
        $item = $order->items()->first();

        $this->service()->allocateOrder($order);

        $entry = Entry::query()->where('order_item_id', $item->id)->first();
        $this->assertNotNull($entry);
        $this->assertSame($user->id, $entry->user_id);
        $this->assertSame($competition->id, $entry->competition_id);
        $this->assertSame($order->id, $entry->order_id);
        $this->assertSame(3, $entry->quantity);

        $tickets = Ticket::query()->where('entry_id', $entry->id)->orderBy('ticket_number')->get();
        $this->assertCount(3, $tickets);
        $this->assertSame([1, 2, 3], $tickets->pluck('ticket_number')->all());
        foreach ($tickets as $ticket) {
            $this->assertSame($competition->id, $ticket->competition_id);
            $this->assertSame($order->id, $ticket->order_id);
            $this->assertSame($user->id, $ticket->user_id);
        }
    }

    public function test_allocation_handles_multiple_order_items_across_different_competitions(): void
    {
        $user = User::factory()->create();
        $competitionA = Competition::factory()->withStatus(CompetitionStatus::Active)->create(['max_entries' => 1000]);
        $competitionB = Competition::factory()->withStatus(CompetitionStatus::Active)->create(['max_entries' => 1000]);
        $order = Order::factory()->for($user)->withStatus(OrderStatus::Paid)->create();
        OrderItem::factory()->for($order)->for($competitionA)->create(['quantity' => 2]);
        OrderItem::factory()->for($order)->for($competitionB)->create(['quantity' => 4]);

        $this->service()->allocateOrder($order);

        $this->assertSame(2, Entry::query()->count());
        $this->assertSame(2, Ticket::query()->where('competition_id', $competitionA->id)->count());
        $this->assertSame(4, Ticket::query()->where('competition_id', $competitionB->id)->count());
        // Numbering is independent per competition — both start at 1.
        $this->assertSame([1, 2], Ticket::query()->where('competition_id', $competitionA->id)->orderBy('ticket_number')->pluck('ticket_number')->all());
        $this->assertSame([1, 2, 3, 4], Ticket::query()->where('competition_id', $competitionB->id)->orderBy('ticket_number')->pluck('ticket_number')->all());
    }

    // -- Eligibility --------------------------------------------------

    public static function nonPayableStatusProvider(): array
    {
        return [
            'pending' => [OrderStatus::Pending],
            'payment_pending' => [OrderStatus::PaymentPending],
            'failed' => [OrderStatus::Failed],
            'cancelled' => [OrderStatus::Cancelled],
            'refunded' => [OrderStatus::Refunded],
        ];
    }

    #[DataProvider('nonPayableStatusProvider')]
    public function test_only_a_paid_order_is_allocated(OrderStatus $status): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create(['max_entries' => 1000]);
        $order = Order::factory()->withStatus($status)->create();
        OrderItem::factory()->for($order)->for($competition)->create(['quantity' => 2]);

        $this->service()->allocateOrder($order);

        $this->assertSame(0, Entry::query()->count());
        $this->assertSame(0, Ticket::query()->count());
    }

    // -- Idempotency --------------------------------------------------

    public function test_allocating_the_same_order_twice_does_not_duplicate(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create(['max_entries' => 1000]);
        $order = $this->paidOrderWithItem($competition, 5);

        $this->service()->allocateOrder($order);
        $this->service()->allocateOrder($order);

        $this->assertSame(1, Entry::query()->count());
        $this->assertSame(5, Ticket::query()->count());
    }

    public function test_a_partially_allocated_order_completes_only_the_missing_item_without_duplicating(): void
    {
        $user = User::factory()->create();
        $competitionA = Competition::factory()->withStatus(CompetitionStatus::Active)->create(['max_entries' => 1000]);
        $competitionB = Competition::factory()->withStatus(CompetitionStatus::Active)->create(['max_entries' => 1000]);
        $order = Order::factory()->for($user)->withStatus(OrderStatus::Paid)->create();
        $itemA = OrderItem::factory()->for($order)->for($competitionA)->create(['quantity' => 2]);
        $itemB = OrderItem::factory()->for($order)->for($competitionB)->create(['quantity' => 3]);

        // Simulate an earlier call that only got through item A (e.g. an
        // interrupted process) — a defensive scenario the per-item
        // existence check guards against.
        $this->service()->allocateOrder($order);
        $this->assertSame(2, Entry::query()->count());

        $existingEntryIdForA = Entry::query()->where('order_item_id', $itemA->id)->value('id');

        $this->service()->allocateOrder($order);

        $this->assertSame(2, Entry::query()->count());
        $this->assertSame($existingEntryIdForA, Entry::query()->where('order_item_id', $itemA->id)->value('id'));
        $this->assertSame(2, Ticket::query()->where('competition_id', $competitionA->id)->count());
        $this->assertSame(3, Ticket::query()->where('competition_id', $competitionB->id)->count());
    }

    // -- Capacity --------------------------------------------------

    public function test_allocation_at_exact_remaining_capacity_succeeds(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create(['max_entries' => 5]);
        // 2 already allocated elsewhere.
        $existingOrder = $this->paidOrderWithItem($competition, 2);
        $this->service()->allocateOrder($existingOrder);

        $order = $this->paidOrderWithItem($competition, 3);
        $this->service()->allocateOrder($order);

        $this->assertSame(5, Ticket::query()->where('competition_id', $competition->id)->count());
    }

    public function test_allocation_exceeding_capacity_is_rejected_and_leaves_no_tickets(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create(['max_entries' => 5]);
        $existingOrder = $this->paidOrderWithItem($competition, 3);
        $this->service()->allocateOrder($existingOrder);

        $order = $this->paidOrderWithItem($competition, 3);

        $this->expectException(CapacityExceededException::class);
        try {
            $this->service()->allocateOrder($order);
        } finally {
            $this->assertSame(3, Ticket::query()->where('competition_id', $competition->id)->count());
            $this->assertSame(0, Entry::query()->where('order_id', $order->id)->count());
            // The order itself is untouched — still paid, not cancelled/failed.
            $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
        }
    }

    // -- Per-user limit --------------------------------------------------

    public function test_per_user_limit_at_exact_remaining_allowance_succeeds(): void
    {
        $user = User::factory()->create();
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create([
            'max_entries' => 1000,
            'max_entries_per_user' => 5,
        ]);
        $this->service()->allocateOrder($this->paidOrderWithItem($competition, 2, $user));

        $order = $this->paidOrderWithItem($competition, 3, $user);
        $this->service()->allocateOrder($order);

        $this->assertSame(5, Ticket::query()->where('competition_id', $competition->id)->where('user_id', $user->id)->count());
    }

    public function test_per_user_limit_exceeded_is_rejected(): void
    {
        $user = User::factory()->create();
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create([
            'max_entries' => 1000,
            'max_entries_per_user' => 5,
        ]);
        $this->service()->allocateOrder($this->paidOrderWithItem($competition, 3, $user));

        $order = $this->paidOrderWithItem($competition, 3, $user);

        $this->expectException(PerUserLimitExceededException::class);
        try {
            $this->service()->allocateOrder($order);
        } finally {
            $this->assertSame(0, Entry::query()->where('order_id', $order->id)->count());
        }
    }

    public function test_null_per_user_limit_is_uncapped(): void
    {
        $user = User::factory()->create();
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create([
            'max_entries' => 1000,
            'max_entries_per_user' => null,
        ]);
        $this->service()->allocateOrder($this->paidOrderWithItem($competition, 50, $user));

        $order = $this->paidOrderWithItem($competition, 50, $user);
        $this->service()->allocateOrder($order);

        $this->assertSame(100, Ticket::query()->where('competition_id', $competition->id)->where('user_id', $user->id)->count());
    }

    // -- Integrity --------------------------------------------------

    public function test_failure_on_one_item_rolls_back_the_whole_order_no_partial_allocation(): void
    {
        $user = User::factory()->create();
        $roomyCompetition = Competition::factory()->withStatus(CompetitionStatus::Active)->create(['max_entries' => 1000]);
        $tightCompetition = Competition::factory()->withStatus(CompetitionStatus::Active)->create(['max_entries' => 2]);
        $order = Order::factory()->for($user)->withStatus(OrderStatus::Paid)->create();
        // Sorted by competition_id ascending during allocation — put the
        // failing item in whichever position, both orders are exercised
        // across test runs since factory ids are sequential either way.
        OrderItem::factory()->for($order)->for($roomyCompetition)->create(['quantity' => 5]);
        OrderItem::factory()->for($order)->for($tightCompetition)->create(['quantity' => 5]); // exceeds cap of 2

        try {
            $this->service()->allocateOrder($order);
            $this->fail('Expected a TicketAllocationException.');
        } catch (CapacityExceededException) {
            // expected
        }

        // Neither item was allocated — not even the one that would have
        // fit on its own.
        $this->assertSame(0, Entry::query()->count());
        $this->assertSame(0, Ticket::query()->count());
    }

    public function test_ticket_number_uniqueness_is_enforced_by_the_database(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create(['max_entries' => 1000]);
        $entry = Entry::factory()->for($competition)->create();
        Ticket::query()->create([
            'entry_id' => $entry->id,
            'competition_id' => $competition->id,
            'order_id' => $entry->order_id,
            'user_id' => $entry->user_id,
            'ticket_number' => 1,
            'allocated_at' => now(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        Ticket::query()->create([
            'entry_id' => $entry->id,
            'competition_id' => $competition->id,
            'order_id' => $entry->order_id,
            'user_id' => $entry->user_id,
            'ticket_number' => 1,
            'allocated_at' => now(),
        ]);
    }

    public function test_entry_per_order_item_uniqueness_is_enforced_by_the_database(): void
    {
        $orderItem = OrderItem::factory()->create();

        Entry::factory()->for($orderItem)->create();

        $this->expectException(\Illuminate\Database\QueryException::class);
        Entry::factory()->for($orderItem)->create();
    }
}
