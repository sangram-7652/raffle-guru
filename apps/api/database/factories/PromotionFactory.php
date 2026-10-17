<?php

namespace Database\Factories;

use App\Models\Competition;
use App\Models\Promotion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Promotion>
 */
class PromotionFactory extends Factory
{
    protected $model = Promotion::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->words(3, true).' giveaway';

        return [
            'name' => ucfirst($name),
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 9999),
            'competition_id' => Competition::factory(),
            'is_enabled' => false,
            'starts_at' => null,
            'ends_at' => null,
            'total_capacity' => 100,
        ];
    }
}
