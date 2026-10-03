<?php

namespace Tests\Feature\Cors;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P11.9 — the browser origins allowed to call the public API. Explicit
 * lists only; credentials stay on, so a wildcard is never acceptable.
 */
class CorsOriginsTest extends TestCase
{
    use RefreshDatabase;

    private function loadCorsConfig(?string $origins, ?string $frontendUrl): array
    {
        $previousOrigins = getenv('CORS_ALLOWED_ORIGINS');
        $previousFrontend = getenv('FRONTEND_URL');

        $this->setEnv('CORS_ALLOWED_ORIGINS', $origins);
        $this->setEnv('FRONTEND_URL', $frontendUrl);

        try {
            return require config_path('cors.php');
        } finally {
            $this->setEnv('CORS_ALLOWED_ORIGINS', $previousOrigins === false ? null : $previousOrigins);
            $this->setEnv('FRONTEND_URL', $previousFrontend === false ? null : $previousFrontend);
        }
    }

    private function setEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            return;
        }

        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    public function test_origins_default_to_the_frontend_url_so_production_keeps_one_origin(): void
    {
        $config = $this->loadCorsConfig(null, 'https://raffleguru.example');

        $this->assertSame(['https://raffleguru.example'], $config['allowed_origins']);
        $this->assertTrue($config['supports_credentials']);
    }

    public function test_cors_allowed_origins_is_a_trimmed_explicit_list_with_empty_entries_dropped(): void
    {
        $config = $this->loadCorsConfig(' http://localhost:8080 , http://localhost:3100 ,', 'http://ignored.test');

        $this->assertSame(['http://localhost:8080', 'http://localhost:3100'], $config['allowed_origins']);
        $this->assertNotContains('*', $config['allowed_origins']);
    }

    public function test_an_allowed_origin_receives_its_own_origin_back_with_credentials(): void
    {
        config(['cors.allowed_origins' => ['http://localhost:8080', 'http://localhost:3100']]);

        $this->withHeaders(['Origin' => 'http://localhost:3100'])
            ->getJson('/api/v1/competitions')
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3100')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
    }

    public function test_an_unlisted_origin_gets_no_cors_grant(): void
    {
        config(['cors.allowed_origins' => ['http://localhost:8080']]);

        $response = $this->withHeaders(['Origin' => 'https://evil.example'])
            ->getJson('/api/v1/competitions');

        $response->assertOk();
        $this->assertNotSame('https://evil.example', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotSame('*', $response->headers->get('Access-Control-Allow-Origin'));
    }
}
