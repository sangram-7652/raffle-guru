<?php

namespace Database\Factories;

use App\Models\Competition;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DrawResult>
 */
class DrawResultFactory extends Factory
{
    protected $model = DrawResult::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'draw_id' => Draw::factory(),
            'competition_id' => Competition::factory(),
            'ticket_id' => Ticket::factory(),
            'ticket_number' => fake()->numberBetween(1, 1000),
            'eligible_ticket_count' => fake()->numberBetween(1, 1000),
            'selected_at' => now(),
        ];
    }
}
