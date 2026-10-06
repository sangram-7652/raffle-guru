<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Services\Admin\AdminManagementService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P11.12 hardening: a remember-me cookie must stop restoring an admin once
 * their password is reset, they are deactivated, or they complete a forced
 * password change.
 *
 * Each case uses a real remember cookie from Laravel's login path, and runs a
 * positive control first. Without that control, a refusal could just mean the
 * cookie never worked.
 */
class AdminRememberTokenTest extends TestCase
{
    use RefreshDatabase;

    private const TEMP = 'Remember-Temp-42';

    private const NEW = 'Remember-New-77';

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function service(): AdminManagementService
    {
        return app(AdminManagementService::class);
    }

    private function superAdmin(): Admin
    {
        return Admin::factory()->superAdmin()->create();
    }

    /**
     * Signs the admin in with "remember me" through Laravel's login path, then
     * clears the in-memory guard and session. The cookie is the only credential
     * left, as it is after a browser restart. Returns the decrypted value: the
     * cookie as the guard sees it after EncryptCookies has run.
     *
     * @return array{0: string, 1: string}
     */
    private function rememberCookieFor(Admin $admin): array
    {
        Auth::guard('admin')->login($admin, true);

        $cookie = collect(app('cookie')->getQueuedCookies())
            ->first(fn ($c) => str_starts_with($c->getName(), 'remember_admin_'));

        $this->assertNotNull($cookie, 'Remember-me login must queue a remember cookie.');

        app('auth')->forgetGuards();
        $this->flushSession();

        return [$cookie->getName(), $cookie->getValue()];
    }

    /**
     * Runs Laravel's real remember-me recall for a request that carries only
     * the cookie: no session user, no stamp. Returns the restored admin or null.
     */
    private function recallWithOnlyTheCookie(string $name, string $value): ?Admin
    {
        app('auth')->forgetGuards();
        $this->flushSession();

        $guard = app('auth')->guard('admin');
        $guard->setRequest(Request::create('/admin', 'GET', [], [$name => $value]));

        return $guard->user();
    }

    private function assertCookieRestores(string $name, string $value, Admin $expected): void
    {
        $restored = $this->recallWithOnlyTheCookie($name, $value);

        $this->assertNotNull($restored, 'The remember cookie should restore the admin before the revocation.');
        $this->assertSame($expected->getKey(), $restored->getKey());
    }

    /**
     * Refusal is checked in both places it can happen: the recall itself, and
     * the HTTP request the browser would make.
     */
    private function assertCookieRefused(string $name, string $value): void
    {
        $this->assertNull($this->recallWithOnlyTheCookie($name, $value), 'The old remember cookie must not restore the admin.');

        app('auth')->forgetGuards();
        $this->flushSession();
        $response = $this->withCookie($name, app('encrypter')->encrypt($value, false))->get('/admin');

        $this->assertSame(
            'http://localhost:8080/admin/login',
            (string) $response->headers->get('Location'),
            'The old remember cookie must not reach the panel over HTTP.',
        );
    }

    // ---- Password reset ---------------------------------------------

    public function test_the_old_remember_cookie_cannot_restore_an_admin_after_a_password_reset(): void
    {
        $target = Admin::factory()->create();
        [$name, $value] = $this->rememberCookieFor($target);

        $this->assertCookieRestores($name, $value, $target);

        $this->service()->resetPassword($this->superAdmin(), $target, self::TEMP, 'Reset after lost laptop');

        $this->assertCookieRefused($name, $value);
    }

    // ---- Deactivation ------------------------------------------------

    public function test_the_old_remember_cookie_cannot_restore_an_admin_after_deactivation(): void
    {
        $target = Admin::factory()->create();
        [$name, $value] = $this->rememberCookieFor($target);

        $this->assertCookieRestores($name, $value, $target);

        $this->service()->deactivate($this->superAdmin(), $target, 'Left the company');

        $this->assertCookieRefused($name, $value);
    }

    public function test_the_old_remember_cookie_stays_dead_after_reactivation(): void
    {
        $actor = $this->superAdmin();
        $target = Admin::factory()->create();
        [$name, $value] = $this->rememberCookieFor($target);

        $this->service()->deactivate($actor, $target, 'Temporary leave');
        $this->service()->activate($actor, $target, 'Returned');

        $this->assertCookieRefused($name, $value);
    }

    // ---- Forced password change --------------------------------------

    public function test_the_old_remember_cookie_cannot_restore_an_admin_after_a_forced_change(): void
    {
        $target = Admin::factory()->create();
        $this->service()->resetPassword($this->superAdmin(), $target, self::TEMP, 'Reset for test');

        // Remember-me sign-in while forced: the cookie restores the admin into
        // the password-change page, the only page reachable in that state.
        [$name, $value] = $this->rememberCookieFor($target->fresh());
        $this->assertCookieRestores($name, $value, $target->fresh());

        $this->service()->completeForcedPasswordChange($target->fresh(), self::TEMP, self::NEW);

        $this->assertCookieRefused($name, $value);
    }

    // ---- Token rotation and secrecy ----------------------------------

    public function test_each_revocation_replaces_the_stored_remember_token(): void
    {
        $target = Admin::factory()->create();
        $before = (string) DB::table('admins')->where('id', $target->id)->value('remember_token');

        $this->service()->resetPassword($this->superAdmin(), $target, self::TEMP, 'Reset for test');
        $afterReset = (string) DB::table('admins')->where('id', $target->id)->value('remember_token');

        $this->service()->completeForcedPasswordChange($target->fresh(), self::TEMP, self::NEW);
        $afterChange = (string) DB::table('admins')->where('id', $target->id)->value('remember_token');

        $this->service()->deactivate($this->superAdmin(), $target, 'Left the company');
        $afterDeactivate = (string) DB::table('admins')->where('id', $target->id)->value('remember_token');

        $tokens = [$before, $afterReset, $afterChange, $afterDeactivate];
        $this->assertCount(4, array_unique($tokens), 'Every revocation must replace the token.');
        $this->assertSame(60, strlen($afterDeactivate));
    }

    public function test_remember_token_never_appears_in_audit_metadata_or_logs(): void
    {
        $target = Admin::factory()->create();
        [$name, $value] = $this->rememberCookieFor($target);
        $tokenBefore = (string) DB::table('admins')->where('id', $target->id)->value('remember_token');

        $this->service()->resetPassword($this->superAdmin(), $target, self::TEMP, 'Reset for test');
        $tokenAfter = (string) DB::table('admins')->where('id', $target->id)->value('remember_token');
        $this->service()->completeForcedPasswordChange($target->fresh(), self::TEMP, self::NEW);
        $this->service()->deactivate($this->superAdmin(), $target->fresh(), 'Left the company');

        $audit = (string) DB::table('admin_audit_logs')->get()->toJson();
        foreach ([$tokenBefore, $tokenAfter, $value] as $secret) {
            $this->assertStringNotContainsString($secret, $audit);
        }

        $logFile = storage_path('logs/laravel.log');
        if (is_file($logFile)) {
            $log = (string) file_get_contents($logFile);
            foreach ([$tokenBefore, $tokenAfter, $value] as $secret) {
                $this->assertStringNotContainsString($secret, $log);
            }
        }

        $this->assertSame(3, AdminAuditLog::query()->count());
    }

    public function test_session_version_behaviour_is_unchanged_by_the_token_rotation(): void
    {
        $target = Admin::factory()->create();
        $before = (int) DB::table('admins')->where('id', $target->id)->value('session_version');

        $this->service()->resetPassword($this->superAdmin(), $target, self::TEMP, 'Reset for test');

        $this->assertSame($before + 1, (int) DB::table('admins')->where('id', $target->id)->value('session_version'));
    }
}
