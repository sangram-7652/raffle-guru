<?php

namespace Database\Factories;

use App\Enums\WinnerClaimStatus;
use App\Models\Winner;
use App\Models\WinnerClaim;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WinnerClaim>
 */
class WinnerClaimFactory extends Factory
{
    protected $model = WinnerClaim::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'winner_id' => Winner::factory(),
            'status' => WinnerClaimStatus::Submitted,
            'submitted_at' => now(),
        ];
    }
}
