<?php

namespace Tests;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Sanctum's EnsureFrontendRequestsAreStateful only starts a session
        // for requests it recognizes as coming from a configured stateful
        // domain (checked via the Referer/Origin header — see
        // config/sanctum.php). Real browser requests always carry one; set
        // it here so tests exercise the same cookie-session code path
        // instead of silently falling back to a sessionless request.
        $this->withHeader('Referer', config('app.frontend_url'));

        // RateLimiter (used by throttle:* middleware — see
        // AppServiceProvider) stores its counters in the cache, which
        // RefreshDatabase does NOT reset between tests. Without this, a
        // test that intentionally exhausts a limiter (RateLimitingTest)
        // leaks 429s into unrelated tests that happen to run afterwards.
        Cache::flush();

        // CSRF token validation is Laravel's own well-tested library code,
        // not application logic — what these tests verify is our auth
        // behavior (registration, login, sessions, ...), which is orthogonal
        // to whether a request happens to carry a matching X-XSRF-TOKEN.
        // This does NOT affect the real application: CSRF stays fully
        // enforced outside the test suite (see config/sanctum.php).
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }
}
