<?php

namespace Database\Factories;

use App\Enums\DrawStatus;
use App\Enums\DrawType;
use App\Models\Competition;
use App\Models\Draw;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Draw>
 */
class DrawFactory extends Factory
{
    protected $model = Draw::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'competition_id' => Competition::factory(),
            'type' => DrawType::Automated,
            'status' => DrawStatus::Running,
            'attempt' => 1,
            'started_at' => now(),
        ];
    }
}
