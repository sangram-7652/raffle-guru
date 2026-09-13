<?php

namespace Database\Factories;

use App\Enums\CompetitionStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Competition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CartItem>
 */
class CartItemFactory extends Factory
{
    protected $model = CartItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cart_id' => Cart::factory(),
            'competition_id' => Competition::factory()->withStatus(CompetitionStatus::Active),
            'quantity' => fake()->numberBetween(1, 10),
            'unit_price' => fake()->numberBetween(39, 199),
            'currency' => 'GBP',
        ];
    }
}
