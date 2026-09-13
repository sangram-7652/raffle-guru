<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subtotal = fake()->numberBetween(99, 9999);

        return [
            'user_id' => User::factory(),
            'reference' => 'RG-'.fake()->unique()->numberBetween(100000, 999999),
            'status' => OrderStatus::Pending,
            'currency' => 'GBP',
            'subtotal' => $subtotal,
            'total' => $subtotal,
        ];
    }

    public function withStatus(OrderStatus $status): static
    {
        return $this->state(fn (array $attributes) => ['status' => $status]);
    }
}
