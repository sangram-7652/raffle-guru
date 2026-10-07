<?php

namespace Tests\Feature\Payment;

use App\Providers\PaymentServiceProvider;
use App\Services\Payments\FakePaymentGateway;
use App\Services\Payments\PaymentGatewayConfigurationException;
use App\Services\Payments\PaymentGatewayInterface;
use App\Services\Payments\RazorpayPaymentGateway;
use Tests\TestCase;

/**
 * P11.16 — PaymentServiceProvider must refuse to even register a
 * PaymentGatewayInterface binding when PAYMENT_GATEWAY=fake under a
 * production environment, before FakePaymentGateway is ever
 * constructed.
 *
 * Exercises the REAL PaymentServiceProvider::register() method — not a
 * copied conditional — by temporarily overriding 'env'/config on THIS
 * test's own application container and constructing a fresh provider
 * instance against it. This is safe to do without any special
 * teardown: createApplication() (see tests/TestCase.php) rebuilds the
 * whole container from scratch before every single test method, so
 * nothing done to $this->app here is visible to another test. No
 * $_ENV/$_SERVER/putenv() is touched anywhere in this file — only the
 * per-test container's 'env' binding and config repository, which is
 * exactly what App\Providers\PaymentServiceProvider itself reads.
 */
class PaymentGatewayGuardTest extends TestCase
{
    private function setEnvironment(string $environment): void
    {
        $this->app->instance('env', $environment);
    }

    private function registerProvider(): void
    {
        (new PaymentServiceProvider($this->app))->register();
    }

    // -- production + fake: must fail closed ---------------------------------

    public function test_production_with_fake_gateway_refuses_to_register(): void
    {
        $this->setEnvironment('production');
        config(['services.payment_gateway' => 'fake']);

        $this->expectException(PaymentGatewayConfigurationException::class);
        $this->expectExceptionMessage('Fake payment gateway is not allowed in production. Set PAYMENT_GATEWAY=razorpay.');

        $this->registerProvider();
    }

    /**
     * Proves the throw happens strictly BEFORE the singleton binding is
     * ever added — not that FakePaymentGateway is merely unusable after
     * being built. The interface binding is explicitly removed first,
     * so there is no ambiguity with whatever the real application boot
     * (before this test even ran) may have already registered.
     */
    public function test_production_with_fake_gateway_never_binds_the_interface(): void
    {
        $this->setEnvironment('production');
        config(['services.payment_gateway' => 'fake']);

        unset($this->app[PaymentGatewayInterface::class]);
        $this->assertFalse($this->app->bound(PaymentGatewayInterface::class));

        try {
            $this->registerProvider();
            $this->fail('Expected PaymentGatewayConfigurationException was not thrown.');
        } catch (PaymentGatewayConfigurationException) {
            // expected
        }

        $this->assertFalse(
            $this->app->bound(PaymentGatewayInterface::class),
            'register() must never reach the singleton call when the guard refuses.',
        );
    }

    // -- production + razorpay: guard must not interfere ----------------------

    public function test_production_with_razorpay_gateway_registers_successfully(): void
    {
        $this->setEnvironment('production');
        config([
            'services.payment_gateway' => 'razorpay',
            'services.razorpay.key_id' => 'rzp_test_dummy',
            'services.razorpay.key_secret' => 'dummy-secret',
            'services.razorpay.webhook_secret' => 'dummy-webhook-secret',
        ]);

        $this->registerProvider();

        $this->assertInstanceOf(RazorpayPaymentGateway::class, $this->app->make(PaymentGatewayInterface::class));
    }

    // -- local + fake: still allowed ---------------------------------------

    public function test_local_with_fake_gateway_registers_successfully(): void
    {
        $this->setEnvironment('local');
        config(['services.payment_gateway' => 'fake']);

        $this->registerProvider();

        $this->assertInstanceOf(FakePaymentGateway::class, $this->app->make(PaymentGatewayInterface::class));
    }

    // -- testing + fake: still allowed ---------------------------------------

    public function test_testing_with_fake_gateway_registers_successfully(): void
    {
        $this->setEnvironment('testing');
        config(['services.payment_gateway' => 'fake']);

        $this->registerProvider();

        $this->assertInstanceOf(FakePaymentGateway::class, $this->app->make(PaymentGatewayInterface::class));
    }

    // -- a non-production, non-local, non-testing environment stays unguarded -

    public function test_staging_with_fake_gateway_registers_successfully(): void
    {
        // The guard is specifically "production + fake", not "anything
        // that isn't local/testing" — a staging environment is neither
        // production nor one of the two environments the task calls out,
        // and must not be accidentally swept into the refusal.
        $this->setEnvironment('staging');
        config(['services.payment_gateway' => 'fake']);

        $this->registerProvider();

        $this->assertInstanceOf(FakePaymentGateway::class, $this->app->make(PaymentGatewayInterface::class));
    }

    // -- error safety -----------------------------------------------------------

    public function test_error_message_exposes_no_secrets(): void
    {
        $this->setEnvironment('production');
        config([
            'services.payment_gateway' => 'fake',
            'services.razorpay.key_id' => 'rzp_live_should_never_appear',
            'services.razorpay.key_secret' => 'super-secret-key-should-never-appear',
            'services.razorpay.webhook_secret' => 'super-secret-webhook-should-never-appear',
        ]);

        try {
            $this->registerProvider();
            $this->fail('Expected PaymentGatewayConfigurationException was not thrown.');
        } catch (PaymentGatewayConfigurationException $e) {
            $message = $e->getMessage();

            $this->assertSame('Fake payment gateway is not allowed in production. Set PAYMENT_GATEWAY=razorpay.', $message);
            $this->assertStringNotContainsString('rzp_live_should_never_appear', $message);
            $this->assertStringNotContainsString('super-secret-key-should-never-appear', $message);
            $this->assertStringNotContainsString('super-secret-webhook-should-never-appear', $message);
        }
    }
}
