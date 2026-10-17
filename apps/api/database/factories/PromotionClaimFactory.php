<?php

namespace Database\Factories;

use App\Enums\PromotionClaimStatus;
use App\Models\Promotion;
use App\Models\PromotionClaim;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PromotionClaim>
 */
class PromotionClaimFactory extends Factory
{
    protected $model = PromotionClaim::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'promotion_id' => Promotion::factory(),
            'user_id' => User::factory(),
            'status' => PromotionClaimStatus::Pending,
        ];
    }
}
