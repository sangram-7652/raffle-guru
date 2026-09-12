<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_endpoint_returns_ok(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200)->assertJson([
            'status' => 'ok',
            'service' => 'raffleguru-api',
            'version' => 'v1',
        ]);
    }

    public function test_health_status_endpoint_reports_dependencies(): void
    {
        $response = $this->getJson('/api/v1/health/status');

        $response->assertJsonStructure([
            'status',
            'service',
            'version',
            'dependencies' => ['database', 'redis'],
        ]);
    }
}
