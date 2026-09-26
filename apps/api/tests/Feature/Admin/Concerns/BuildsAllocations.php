<?php

namespace Tests\Feature\Admin\Concerns;

use App\Enums\CompetitionStatus;
use App\Enums\OrderStatus;
use App\Models\Competition;
use App\Models\Entry;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Ticket;
use App\Models\User;

/**
 * Builds the same coherent shape TicketAllocationService produces for a
 * paid order line: user -> order -> order item -> one entry -> N tickets
 * numbered sequentially within the competition. (The individual model
 * factories create unrelated parents, which would make relationship
 * assertions meaningless.)
 */
trait BuildsAllocations
{
    /**
     * @param  array<string, mixed>  $orderAttributes
     */
    protected function allocate(
        int $tickets = 3,
        ?Competition $competition = null,
        ?User $user = null,
        array $orderAttributes = [],
        int $firstTicketNumber = 1,
    ): Entry {
        $competition ??= Competition::factory()->withStatus(CompetitionStatus::Active)->create();
        $user ??= User::factory()->create();

        $order = Order::factory()->for($user)->withStatus(OrderStatus::Paid)->create(array_merge([
            'subtotal' => 350 * $tickets,
            'total' => 350 * $tickets,
        ], $orderAttributes));

        $item = OrderItem::factory()->for($order)->create([
            'competition_id' => $competition->id,
            'competition_title' => $competition->title,
            'quantity' => $tickets,
            'unit_price' => 350,
            'line_total' => 350 * $tickets,
        ]);

        $entry = Entry::factory()->create([
            'user_id' => $user->id,
            'competition_id' => $competition->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'quantity' => $tickets,
        ]);

        for ($i = 0; $i < $tickets; $i++) {
            Ticket::factory()->create([
                'entry_id' => $entry->id,
                'competition_id' => $competition->id,
                'order_id' => $order->id,
                'user_id' => $user->id,
                'ticket_number' => $firstTicketNumber + $i,
            ]);
        }

        return $entry;
    }
}
