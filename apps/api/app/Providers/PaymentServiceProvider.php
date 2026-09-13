<?php

namespace App\Providers;

use App\Services\Payments\FakePaymentGateway;
use App\Services\Payments\PaymentGatewayInterface;
use App\Services\Payments\RazorpayPaymentGateway;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the one gateway implementation the rest of the app depends on
 * (see docs/PAYMENTS_DESIGN_P6.md "Gateway abstraction"). Swappable via
 * PAYMENT_GATEWAY for local/Docker verification without a real Razorpay
 * account — PHPUnit tests bind FakePaymentGateway directly instead of
 * relying on this env var, so they stay hermetic regardless of .env.
 */
class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PaymentGatewayInterface::class, function () {
            if (config('services.payment_gateway') === 'fake') {
                return new FakePaymentGateway();
            }

            return new RazorpayPaymentGateway(
                (string) config('services.razorpay.key_id'),
                (string) config('services.razorpay.key_secret'),
                (string) config('services.razorpay.webhook_secret'),
            );
        });
    }
}
