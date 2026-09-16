<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * P11.3 §15/§19 — the admin login form must throttle repeated attempts
 * server-side. This isn't new application code: Filament's own
 * Auth\Pages\Login::authenticate() already calls
 * DanHarrin\LivewireRateLimiting\WithRateLimiting::rateLimit(5) (5
 * attempts per 60s, keyed by IP) before touching credentials at all —
 * see vendor/filament/filament/src/Auth/Pages/Login.php. This test
 * exists to prove that behavior is actually active for this panel, not
 * to re-test Filament's own library code.
 */
class AdminLoginRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_repeated_failed_login_attempts_are_throttled(): void
    {
        $admin = Admin::factory()->create(['password' => 'password123']);

        for ($i = 0; $i < 5; $i++) {
            Livewire::test(Login::class)
                ->set('data.email', $admin->email)
                ->set('data.password', 'wrong-password')
                ->call('authenticate');
        }

        // The 6th attempt, even with the CORRECT password, must still be
        // blocked — proving this throttles attempts, not just wrong
        // passwords.
        Livewire::test(Login::class)
            ->set('data.email', $admin->email)
            ->set('data.password', 'password123')
            ->call('authenticate')
            ->assertNotified();

        $this->assertGuest('admin');
    }
}
