<?php

namespace Database\Factories;

use App\Enums\CompetitionStatus;
use App\Models\Competition;
use App\Models\Entry;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Entry>
 *
 * Tests that need the user/competition/order to actually agree with a
 * specific OrderItem should override with `->for(...)` — this default
 * just produces an internally-consistent, independent entry.
 */
class EntryFactory extends Factory
{
    protected $model = Entry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'competition_id' => Competition::factory()->withStatus(CompetitionStatus::Active),
            'order_id' => Order::factory(),
            'order_item_id' => OrderItem::factory(),
            'quantity' => fake()->numberBetween(1, 10),
        ];
    }
}
