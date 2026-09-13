<?php

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'gateway' => 'razorpay',
            'gateway_order_id' => 'order_'.fake()->unique()->bothify('??????????????'),
            'gateway_payment_id' => null,
            'gateway_signature' => null,
            'amount' => fake()->numberBetween(99, 9999),
            'currency' => 'GBP',
            'status' => PaymentStatus::Created,
            'failure_code' => null,
            'failure_message' => null,
            'paid_at' => null,
            'metadata' => null,
        ];
    }

    public function withStatus(PaymentStatus $status): static
    {
        return $this->state(fn (array $attributes) => ['status' => $status]);
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentStatus::Paid,
            'gateway_payment_id' => 'pay_'.fake()->unique()->bothify('??????????????'),
            'paid_at' => now(),
        ]);
    }
}
