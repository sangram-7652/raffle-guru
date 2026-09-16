<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * P11.1 — admin authentication through the Filament panel's `admin` guard.
 * See the P11 architecture plan §3 for why this is a dedicated
 * guard/provider/model, never the customer `web` guard.
 */
class AdminAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Livewire component tests for a panel page need a "current
        // panel" bootstrapped — normally set by the panel's own route
        // middleware when a real request hits /admin/*. Setting it
        // explicitly here is the supported Filament testing entry point
        // (Filament\FilamentManager::setCurrentPanel()), not a private
        // internal, and avoids relying on Laravel's HTTP test client
        // carrying request-scoped state between two separate simulated
        // requests — a fragility this codebase's own LogoutTest already
        // documents and works around for the customer auth flow.
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_a_valid_admin_can_authenticate_through_the_panel_login_form(): void
    {
        $admin = Admin::factory()->create(['password' => 'password123']);

        Livewire::test(Login::class)
            ->set('data.email', $admin->email)
            ->set('data.password', 'password123')
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($admin, 'admin');
        $this->assertGuest('web');
    }

    public function test_login_is_rejected_with_an_invalid_password(): void
    {
        $admin = Admin::factory()->create(['password' => 'password123']);

        Livewire::test(Login::class)
            ->set('data.email', $admin->email)
            ->set('data.password', 'wrong-password')
            ->call('authenticate')
            ->assertHasFormErrors();

        $this->assertGuest('admin');
    }

    public function test_login_is_rejected_for_an_unknown_email(): void
    {
        Livewire::test(Login::class)
            ->set('data.email', 'nobody@example.com')
            ->set('data.password', 'whatever123')
            ->call('authenticate')
            ->assertHasFormErrors();

        $this->assertGuest('admin');
    }

    /**
     * An inactive admin is a provisioned, real account whose credentials
     * are otherwise correct — Admin::canAccessPanel() must still block
     * the login, through the SAME generic failure Filament shows for a
     * wrong password (matching this codebase's existing customer-auth
     * anti-enumeration convention — see docs/AUTHENTICATION.md — rather
     * than a distinct "this account is disabled" message that would leak
     * which emails are real accounts).
     */
    public function test_an_inactive_admin_cannot_authenticate(): void
    {
        $admin = Admin::factory()->inactive()->create(['password' => 'password123']);

        Livewire::test(Login::class)
            ->set('data.email', $admin->email)
            ->set('data.password', 'password123')
            ->call('authenticate')
            ->assertHasFormErrors();

        $this->assertGuest('admin');
    }

    /**
     * Same fact, asserted independently of the Filament login form: an
     * inactive admin fails Guard::attempt() outright, because
     * Auth\SessionGuard checks Authenticatable::canAccessPanel() is not
     * part of attempt() itself — this is Filament's own
     * isUserAllowedToAccessPanel() gate. This test instead proves the
     * MODEL-level contract (Admin::canAccessPanel()) independent of
     * Filament's Livewire internals, so it stays valid even if the
     * login page implementation changes.
     */
    public function test_inactive_admin_fails_the_panel_access_check_directly(): void
    {
        $admin = Admin::factory()->inactive()->create();

        $this->assertFalse($admin->canAccessPanel(Filament::getPanel('admin')));
    }

    public function test_active_admin_passes_the_panel_access_check_directly(): void
    {
        $admin = Admin::factory()->create();

        $this->assertTrue($admin->canAccessPanel(Filament::getPanel('admin')));
    }

    public function test_unauthenticated_request_to_the_panel_redirects_to_login(): void
    {
        $response = $this->get('/admin');

        $response->assertRedirect('/admin/login');
    }

    public function test_authenticated_admin_can_reach_the_panel_dashboard(): void
    {
        $admin = Admin::factory()->create();

        $response = $this->actingAs($admin, 'admin')->get('/admin');

        $response->assertOk();
    }

    /**
     * Distinct from "an inactive admin can't log in" above: an admin
     * deactivated mid-session must lose access on their very next
     * request, not just be blocked at the next login. Filament's
     * Authenticate middleware (registered as this panel's
     * authMiddleware) calls canAccessPanel() fresh on every request —
     * see vendor/filament/filament/src/Http/Middleware/Authenticate.php
     * — so this is enforced by Filament itself; this test proves it's
     * actually wired up for the `admin` guard/panel, not just true in
     * theory.
     */
    public function test_deactivating_an_admin_mid_session_revokes_panel_access_on_the_next_request(): void
    {
        $admin = Admin::factory()->create();
        $this->actingAs($admin, 'admin');

        $this->get('/admin')->assertOk();

        $admin->update(['is_active' => false]);

        $this->get('/admin')->assertForbidden();
    }

    public function test_admin_can_log_out(): void
    {
        $admin = Admin::factory()->create();
        $this->actingAs($admin, 'admin');

        Auth::guard('admin')->logout();

        $this->assertGuest('admin');
    }
}
