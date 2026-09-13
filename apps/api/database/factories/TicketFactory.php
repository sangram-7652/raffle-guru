<?php

namespace Database\Factories;

use App\Enums\CompetitionStatus;
use App\Models\Competition;
use App\Models\Entry;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ticket>
 *
 * Tests that need the denormalized competition/order/user to actually
 * agree with a specific Entry should override with `->for(...)`.
 */
class TicketFactory extends Factory
{
    protected $model = Ticket::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'entry_id' => Entry::factory(),
            'competition_id' => Competition::factory()->withStatus(CompetitionStatus::Active),
            'order_id' => Order::factory(),
            'user_id' => User::factory(),
            'ticket_number' => fake()->unique()->numberBetween(1, 1000000),
            'allocated_at' => now(),
        ];
    }
}
