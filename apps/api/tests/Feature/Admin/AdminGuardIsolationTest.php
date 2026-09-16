<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The core security property the whole `admin` guard/provider design
 * rests on (P11 architecture plan §3): a customer session must never
 * satisfy `auth:admin`, and an admin session must never satisfy the
 * customer API's `auth:sanctum`/`web` guard. Also proves the existing
 * customer authentication surface is unaffected by P11's additions.
 */
class AdminGuardIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_customer_session_does_not_authenticate_the_admin_guard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web');

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertGuest('admin');
    }

    public function test_an_admin_session_does_not_authenticate_the_customer_guard(): void
    {
        $admin = Admin::factory()->create();

        $this->actingAs($admin, 'admin');

        $this->assertAuthenticatedAs($admin, 'admin');
        $this->assertGuest('web');
    }

    /**
     * An admin session must not grant access to the customer-facing API
     * — proves isolation from the customer's own point of view, not just
     * the guard facade in isolation.
     */
    public function test_admin_session_cannot_reach_the_customer_auth_user_endpoint(): void
    {
        $admin = Admin::factory()->create();
        $this->actingAs($admin, 'admin');

        $response = $this->getJson('/api/v1/auth/user');

        $response->assertUnauthorized();
    }

    /**
     * Existing customer registration/login is unaffected by the new
     * `admin` guard/provider existing alongside it — a direct smoke test
     * of the P3 flow, in addition to the full existing Auth suite
     * (tests/Feature/Auth/**) still passing unmodified as part of
     * regression.
     */
    public function test_existing_customer_login_flow_still_works(): void
    {
        $user = User::factory()->create(['password' => 'password123']);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $response->assertOk();
        $this->assertAuthenticatedAs($user, 'web');
    }
}
