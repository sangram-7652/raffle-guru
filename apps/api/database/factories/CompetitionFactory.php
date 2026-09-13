<?php

namespace Database\Factories;

use App\Enums\CompetitionStatus;
use App\Models\Competition;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Competition>
 */
class CompetitionFactory extends Factory
{
    protected $model = Competition::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->words(3, true).' '.fake()->randomElement(['Giveaway', 'Competition', 'Draw']);
        $maxEntries = fake()->numberBetween(5_000, 100_000);

        return [
            'slug' => Str::slug($title).'-'.fake()->unique()->numberBetween(1000, 9999),
            'title' => ucfirst($title),
            'short_description' => fake()->sentence(12),
            'description' => [fake()->paragraph(), fake()->paragraph()],
            'category' => fake()->randomElement(['Cars & Bikes', 'Cash', 'Luxury', 'Tech', 'Lifestyle']),
            'status' => CompetitionStatus::Active,
            'entry_price' => fake()->numberBetween(39, 199),
            'currency' => 'GBP',
            'max_entries' => $maxEntries,
            'max_entries_per_user' => null,
            'images' => [fake()->imageUrl()],
            'skill_question' => [
                'prompt' => 'What colour is the sky on a clear day?',
                'options' => ['Green', 'Blue', 'Red', 'Purple'],
                'answer_index' => 1,
            ],
            'starts_at' => null,
            'ends_at' => null,
            'draw_at' => fake()->dateTimeBetween('+1 week', '+3 weeks'),
            'is_featured' => fake()->boolean(30),
        ];
    }

    public function featured(): static
    {
        return $this->state(fn (array $attributes) => ['is_featured' => true]);
    }

    public function withStatus(CompetitionStatus $status): static
    {
        return $this->state(fn (array $attributes) => ['status' => $status]);
    }
}
