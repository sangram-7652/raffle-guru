<?php

namespace Database\Factories;

use App\Models\Winner;
use App\Models\WinnerAuditLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WinnerAuditLog>
 */
class WinnerAuditLogFactory extends Factory
{
    protected $model = WinnerAuditLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'winner_id' => Winner::factory(),
            'event' => 'winner_created',
            'actor_type' => 'system',
        ];
    }
}
