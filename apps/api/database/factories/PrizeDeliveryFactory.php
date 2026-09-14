<?php

namespace Database\Factories;

use App\Enums\PrizeDeliveryStatus;
use App\Models\PrizeDelivery;
use App\Models\Winner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrizeDelivery>
 */
class PrizeDeliveryFactory extends Factory
{
    protected $model = PrizeDelivery::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'winner_id' => Winner::factory(),
            'status' => PrizeDeliveryStatus::Pending,
        ];
    }
}
