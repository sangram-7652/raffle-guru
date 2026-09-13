<?php

namespace Database\Factories;

use App\Enums\CompetitionStatus;
use App\Models\Competition;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $unitPrice = fake()->numberBetween(39, 199);
        $quantity = fake()->numberBetween(1, 10);

        return [
            'order_id' => Order::factory(),
            'competition_id' => Competition::factory()->withStatus(CompetitionStatus::Active),
            'prize_id' => null,
            'competition_title' => fake()->sentence(3),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'currency' => 'GBP',
            'line_total' => $unitPrice * $quantity,
        ];
    }
}
