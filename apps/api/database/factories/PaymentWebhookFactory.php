<?php

namespace Database\Factories;

use App\Models\PaymentWebhook;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentWebhook>
 */
class PaymentWebhookFactory extends Factory
{
    protected $model = PaymentWebhook::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'gateway' => 'razorpay',
            'event_id' => hash('sha256', fake()->unique()->uuid()),
            'event_type' => 'payment.captured',
            'metadata' => null,
            'processed_at' => now(),
        ];
    }
}
