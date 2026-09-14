<?php

namespace Database\Factories;

use App\Enums\WinnerStatus;
use App\Models\Competition;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Winner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Winner>
 */
class WinnerFactory extends Factory
{
    protected $model = Winner::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'competition_id' => Competition::factory(),
            'draw_id' => Draw::factory(),
            'draw_result_id' => DrawResult::factory(),
            'ticket_id' => Ticket::factory(),
            'user_id' => User::factory(),
            'status' => WinnerStatus::PendingContact,
        ];
    }

    public function withStatus(WinnerStatus $status): static
    {
        return $this->state(fn (array $attributes) => ['status' => $status]);
    }
}
