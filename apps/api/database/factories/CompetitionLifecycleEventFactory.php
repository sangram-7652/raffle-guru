<?php

namespace Database\Factories;

use App\Models\Competition;
use App\Models\CompetitionLifecycleEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompetitionLifecycleEvent>
 */
class CompetitionLifecycleEventFactory extends Factory
{
    protected $model = CompetitionLifecycleEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'competition_id' => Competition::factory(),
            'from_status' => 'scheduled',
            'to_status' => 'active',
            'reason' => 'starts_at reached',
        ];
    }
}
