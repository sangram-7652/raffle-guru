<?php

namespace Tests\Feature\Security;

use App\Providers\AppServiceProvider;
use Tests\TestCase;

/**
 * P12.5 — AppServiceProvider must refuse to boot a production
 * environment whose session cookie isn't marked Secure (see
 * docs/PRODUCTION_DEPLOYMENT.md §2 and docs/GO_LIVE_CHECKLIST.md
 * "J. HTTPS"). Same testing approach as
 * tests/Feature/Payment/PaymentGatewayGuardTest.php: exercise the REAL
 * register() method against this test's own application container
 * (rebuilt from scratch before every test method — see
 * tests/TestCase.php), never touching real $_ENV/$_SERVER.
 */
class ProductionSecureCookieGuardTest extends TestCase
{
    private function setEnvironment(string $environment): void
    {
        $this->app->instance('env', $environment);
    }

    private function registerProvider(): void
    {
        (new AppServiceProvider($this->app))->register();
    }

    public function test_production_without_secure_cookie_refuses_to_boot(): void
    {
        $this->setEnvironment('production');
        config(['session.secure' => false]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SESSION_SECURE_COOKIE must be true in production. See docs/PRODUCTION_DEPLOYMENT.md §2.');

        $this->registerProvider();
    }

    public function test_production_with_secure_cookie_boots_successfully(): void
    {
        $this->setEnvironment('production');
        config(['session.secure' => true]);

        $this->registerProvider();

        $this->addToAssertionCount(1);
    }

    public function test_local_without_secure_cookie_still_boots(): void
    {
        $this->setEnvironment('local');
        config(['session.secure' => false]);

        $this->registerProvider();

        $this->addToAssertionCount(1);
    }

    public function test_testing_without_secure_cookie_still_boots(): void
    {
        $this->setEnvironment('testing');
        config(['session.secure' => false]);

        $this->registerProvider();

        $this->addToAssertionCount(1);
    }

    public function test_staging_without_secure_cookie_still_boots(): void
    {
        // The guard is specifically "production + insecure cookie", not
        // "anything that isn't local/testing" — staging is neither and
        // must not be accidentally swept into the refusal.
        $this->setEnvironment('staging');
        config(['session.secure' => false]);

        $this->registerProvider();

        $this->addToAssertionCount(1);
    }
}
