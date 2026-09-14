<?php

namespace Tests\Feature\Draw\Concerns;

use App\Enums\CompetitionStatus;
use App\Enums\OrderStatus;
use App\Models\Competition;
use App\Models\Entry;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Ticket;
use App\Models\User;

/**
 * Builds ticket fixtures the same way TicketAllocationService (P7) would
 * have produced them for real: sequential ticket_number per competition,
 * one Order/OrderItem/Entry per "batch", tickets from a genuinely
 * `paid` order (optionally moved to `refunded` afterwards, exactly as
 * PaymentService::markRefunded() would leave them — entries/tickets
 * untouched, only the order's own status changes).
 */
trait CreatesDrawFixtures
{
    /**
     * @return array{competition: Competition, eligibleTicketNumbers: list<int>, ineligibleTicketNumbers: list<int>}
     */
    private function createCompetitionWithTickets(
        CompetitionStatus $status,
        int $eligibleCount,
        int $refundedCount = 0,
    ): array {
        $competition = Competition::factory()->withStatus($status)->create([
            'max_entries' => $eligibleCount + $refundedCount + 10,
        ]);

        $next = 1;
        [$eligibleNumbers, $next] = $this->allocateBatch($competition, $eligibleCount, OrderStatus::Paid, $next);
        [$ineligibleNumbers] = $this->allocateBatch($competition, $refundedCount, OrderStatus::Refunded, $next);

        return [
            'competition' => $competition,
            'eligibleTicketNumbers' => $eligibleNumbers,
            'ineligibleTicketNumbers' => $ineligibleNumbers,
        ];
    }

    /**
     * @return array{0: list<int>, 1: int} ticket numbers allocated, and the next free number
     */
    private function allocateBatch(Competition $competition, int $count, OrderStatus $orderStatus, int $startNumber): array
    {
        if ($count < 1) {
            return [[], $startNumber];
        }

        $user = User::factory()->create();
        $order = Order::factory()->withStatus($orderStatus)->for($user)->create();
        $item = OrderItem::factory()->for($order)->for($competition)->create(['quantity' => $count]);
        $entry = Entry::query()->create([
            'user_id' => $user->id,
            'competition_id' => $competition->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'quantity' => $count,
        ]);

        $numbers = [];
        $rows = [];
        $now = now();
        for ($i = 0; $i < $count; $i++) {
            $number = $startNumber + $i;
            $numbers[] = $number;
            $rows[] = [
                'entry_id' => $entry->id,
                'competition_id' => $competition->id,
                'order_id' => $order->id,
                'user_id' => $user->id,
                'ticket_number' => $number,
                'allocated_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        Ticket::insert($rows);

        return [$numbers, $startNumber + $count];
    }
}
