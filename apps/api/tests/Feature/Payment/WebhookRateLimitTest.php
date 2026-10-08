<?php

namespace Tests\Feature\Payment;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P12.3 — the payment webhook route is the one unauthenticated public
 * POST endpoint in this API; throttle:payment-webhook (120/min/IP, see
 * AppServiceProvider and docs/LOAD_AND_CONCURRENCY.md "Rate limiting")
 * bounds how much an unauthenticated flood can cost, independent of
 * signature validity.
 */
class WebhookRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_route_is_throttled_after_120_requests_per_minute(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->withoutMiddleware(ValidateCsrfToken::class)
                ->call('POST', '/api/v1/payments/webhook/razorpay', [], [], [], [
                    'HTTP_X_RAZORPAY_SIGNATURE' => 'not-a-real-signature',
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_ACCEPT' => 'application/json',
                ], '{}')
                ->assertStatus(400);
        }

        $response = $this->withoutMiddleware(ValidateCsrfToken::class)
            ->call('POST', '/api/v1/payments/webhook/razorpay', [], [], [], [
                'HTTP_X_RAZORPAY_SIGNATURE' => 'not-a-real-signature',
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ], '{}');

        $response->assertTooManyRequests();
    }
}
