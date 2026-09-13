<?php

namespace Database\Factories;

use App\Models\Competition;
use App\Models\Prize;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Prize>
 */
class PrizeFactory extends Factory
{
    protected $model = Prize::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'competition_id' => Competition::factory(),
            'title' => fake()->words(3, true),
            'description' => [fake()->sentence(), fake()->sentence()],
            'value' => fake()->numberBetween(50_000, 9_000_000),
            'currency' => 'GBP',
            'cash_alternative_value' => null,
            'quantity' => 1,
        ];
    }

    public function withCashAlternative(): static
    {
        return $this->state(fn (array $attributes) => [
            'cash_alternative_value' => (int) round($attributes['value'] * 0.75),
        ]);
    }
}
