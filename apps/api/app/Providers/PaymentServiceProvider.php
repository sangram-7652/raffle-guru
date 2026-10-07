<?php

namespace App\Providers;

use App\Services\Payments\FakePaymentGateway;
use App\Services\Payments\PaymentGatewayConfigurationException;
use App\Services\Payments\PaymentGatewayInterface;
use App\Services\Payments\RazorpayPaymentGateway;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the one gateway implementation the rest of the app depends on
 * (see docs/PAYMENTS_DESIGN_P6.md "Gateway abstraction"). Swappable via
 * PAYMENT_GATEWAY for local/Docker verification without a real Razorpay
 * account — PHPUnit tests bind FakePaymentGateway directly instead of
 * relying on this env var, so they stay hermetic regardless of .env.
 *
 * P11.16: `PAYMENT_GATEWAY=fake` in a production environment is refused
 * here, unconditionally — not inside the singleton factory below, which
 * only runs the first time something actually resolves
 * PaymentGatewayInterface. This provider is never deferred, so
 * register() runs on every request/command; a misconfigured production
 * deployment must fail at boot even if whatever happens to run first
 * never touches payments at all, and FakePaymentGateway must never be
 * constructed even once — not "guarded after being built".
 */
class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->environment('production') && config('services.payment_gateway') === 'fake') {
            throw new PaymentGatewayConfigurationException(
                'Fake payment gateway is not allowed in production. Set PAYMENT_GATEWAY=razorpay.',
            );
        }

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
