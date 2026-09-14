<?php

namespace Database\Factories;

use App\Models\Competition;
use App\Models\Draw;
use App\Models\DrawAuditLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DrawAuditLog>
 */
class DrawAuditLogFactory extends Factory
{
    protected $model = DrawAuditLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'draw_id' => Draw::factory(),
            'competition_id' => Competition::factory(),
            'event' => 'draw.started',
            'metadata' => null,
        ];
    }
}
