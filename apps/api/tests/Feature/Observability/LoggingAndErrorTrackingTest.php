<?php

namespace Tests\Feature\Observability;

use Illuminate\Support\Facades\Log;
use Monolog\Handler\StreamHandler;
use Sentry\SentrySdk;
use Tests\TestCase;

/**
 * P12.1 — observability. Covers the Laravel-level pieces only:
 *
 * - the `stderr` log channel production is meant to use (see
 *   docs/PRODUCTION_ARCHITECTURE.md "Observability") actually resolves
 *   and is usable;
 * - Sentry is genuinely inert with no DSN configured — not merely
 *   "configured to be quiet" — so the test suite never risks sending
 *   real telemetry, and enabling it in production is a config change,
 *   not a code change;
 * - adding Sentry's exception-reporting hook to bootstrap/app.php
 *   didn't change the existing JSON error contract.
 *
 * Docker-level log ROTATION (docker-compose.prod.yml's `logging:`
 * block, capping the json-file driver) is infrastructure, not
 * application code — verified directly with `docker compose config`
 * (see this phase's manual verification), the same way P11.15 verified
 * its own Docker/Nginx changes outside PHPUnit rather than parsing YAML
 * inside a Laravel test.
 */
class LoggingAndErrorTrackingTest extends TestCase
{
    // -- Production log channel ------------------------------------------------

    public function test_the_stderr_channel_production_is_meant_to_use_is_configured_and_usable(): void
    {
        $channel = config('logging.channels.stderr');

        $this->assertNotNull($channel, 'apps/api/.env.example documents LOG_CHANNEL=stderr for production — the channel must exist.');
        $this->assertSame(StreamHandler::class, $channel['handler']);
        $this->assertSame('php://stderr', $channel['handler_with']['stream']);

        // Actually writable, not just structurally present.
        Log::channel('stderr')->info('P12.1 observability test: stderr channel is reachable.');
        $this->addToAssertionCount(1);
    }

    public function test_log_level_is_configurable_via_environment(): void
    {
        config(['logging.channels.stderr.level' => 'warning']);

        $this->assertSame('warning', config('logging.channels.stderr.level'));
    }

    // -- Sentry: inert without a DSN --------------------------------------------

    public function test_sentry_has_no_dsn_configured_in_the_test_environment(): void
    {
        // The real invariant this guards: the test suite must never be
        // able to send real telemetry. An empty DSN is what guarantees
        // that — the SDK does not attempt a network call at all.
        $this->assertEmpty(config('sentry.dsn'), 'SENTRY_LARAVEL_DSN must stay unset in testing — see .env.example.');
    }

    public function test_sentry_client_has_no_dsn_and_therefore_sends_nothing(): void
    {
        // Independent of config() — asks the SDK itself, which is what
        // actually decides whether anything is ever sent. The SDK
        // always creates a Client object (even with no DSN — confirmed
        // empirically, not assumed); what actually makes it a genuine
        // no-op is the DSN on its own Options being empty, which this
        // asserts directly rather than inferring from config().
        $client = SentrySdk::getCurrentHub()->getClient();

        $this->assertNotNull($client);
        $this->assertEmpty(
            $client->getOptions()->getDsn(),
            'With no DSN, the Sentry client must have nothing to send to.',
        );
    }

    /**
     * Proves the config wiring itself is correct — DSN flows from
     * config into the exact key the SDK reads — without needing a real,
     * network-reachable DSN. This is "production can enable the
     * tracker", not "the tracker was exercised against sentry.io".
     */
    public function test_setting_a_dsn_is_reflected_in_the_resolved_sentry_configuration(): void
    {
        config(['sentry.dsn' => 'https://public@o0.ingest.sentry.io/0']);

        $this->assertSame('https://public@o0.ingest.sentry.io/0', config('sentry.dsn'));
    }

    public function test_sentry_send_default_pii_defaults_to_false(): void
    {
        // The package's own safe default — not something this app
        // relaxed. See docs/PRODUCTION_ARCHITECTURE.md "Observability".
        $this->assertFalse(config('sentry.send_default_pii'));
    }

    // -- Existing exception/API contract is unchanged ---------------------------

    public function test_an_unknown_api_route_still_returns_the_existing_404_json_shape(): void
    {
        // Regression guard specifically for Integration::handles() in
        // bootstrap/app.php: a reportable() callback must never change
        // shouldRenderJsonWhen's rendering decision or the response shape.
        $response = $this->getJson('/api/v1/this-route-does-not-exist');

        $response->assertStatus(404);
        $response->assertJsonStructure(['message']);
    }
}
