<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminAbility;
use App\Enums\AdminRole;
use App\Filament\Pages\ChangeAdminPassword;
use App\Filament\Resources\Admins\Pages\ListAdmins;
use App\Filament\Resources\Admins\Pages\ViewAdmin;
use App\Http\Middleware\Admin\EnforceAdminSession;
use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Models\User;
use App\Policies\AdminPolicy;
use App\Services\Admin\AdminManagementException;
use App\Services\Admin\AdminManagementService;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * P11.12 security hardening: temporary passwords and forced change, session
 * generation revocation, last_login_at, required reasons, and self-management.
 *
 * Sign-ins go through Filament's real Login page. Requests go through the real
 * panel middleware stack.
 */
class AdminSecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    private const TEMP = 'Temporary-Pass-42';

    private const NEW = 'Brand-New-Pass-77';

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function superAdmin(array $attributes = []): Admin
    {
        return Admin::factory()->superAdmin()->create(array_merge(['password' => 'Super-Pass-123'], $attributes));
    }

    private function operations(array $attributes = []): Admin
    {
        return Admin::factory()->create(array_merge(['password' => 'Ops-Pass-12345'], $attributes));
    }

    private function service(): AdminManagementService
    {
        return app(AdminManagementService::class);
    }

    /**
     * Signs in through the real Login page. Returns the Livewire test so the
     * caller can assert on form errors.
     */
    private function signInThroughLoginPage(string $email, string $password)
    {
        return Livewire::test(Login::class)
            ->set('data.email', $email)
            ->set('data.password', $password)
            ->call('authenticate');
    }

    private function row(Admin $admin): object
    {
        return DB::table('admins')->where('id', $admin->getKey())->first();
    }

    // ---- Authorization ----------------------------------------------

    public function test_only_a_super_admin_can_reset_another_admins_password(): void
    {
        $target = $this->operations();

        try {
            $this->service()->resetPassword($this->operations(), $target, self::TEMP, 'Unauthorised attempt');
            $this->fail('Operations must not reset passwords.');
        } catch (AuthorizationException) {
        }

        $this->assertFalse((bool) $this->row($target)->must_change_password);
        $this->assertTrue(Hash::check('Ops-Pass-12345', $this->row($target)->password));
    }

    public function test_an_admin_cannot_reset_their_own_password_through_this_path(): void
    {
        $self = $this->superAdmin();

        $this->expectException(AuthorizationException::class);

        $this->service()->resetPassword($self, $self, self::TEMP, 'Self reset attempt');
    }

    public function test_a_guest_is_denied_the_reset_page_and_a_customer_is_denied_too(): void
    {
        $target = $this->operations();

        $this->get('/admin/admins/'.$target->id)->assertRedirect('/admin/login');

        $customer = User::factory()->create();
        $this->actingAs($customer, 'web')->get('/admin/admins/'.$target->id)->assertRedirect('/admin/login');
    }

    public function test_crafted_livewire_reset_by_operations_changes_nothing(): void
    {
        $target = $this->operations();
        $this->actingAs($this->operations(), 'admin');

        try {
            Livewire::test(ViewAdmin::class, ['record' => $target->id])
                ->callAction('resetPassword', data: [
                    'temporary_password' => self::TEMP,
                    'temporary_password_confirmation' => self::TEMP,
                    'reason' => 'Escalation attempt',
                ]);
        } catch (\Throwable) {
        }

        $this->assertFalse((bool) $this->row($target)->must_change_password);
        $this->assertSame(0, AdminAuditLog::query()->where('event', AdminAuditLog::EVENT_PASSWORD_RESET)->count());
    }

    // ---- Reset: hashing, audit, secrets -----------------------------

    public function test_super_admin_resets_another_admin_password_to_a_hashed_temporary_one(): void
    {
        $actor = $this->superAdmin();
        $target = $this->operations();

        $this->service()->resetPassword($actor, $target, self::TEMP, 'Locked out after device loss');

        $row = $this->row($target);
        $this->assertTrue((bool) $row->must_change_password);
        $this->assertNotSame(self::TEMP, $row->password);
        $this->assertTrue(Hash::check(self::TEMP, $row->password));
    }

    public function test_the_reset_is_audited_with_actor_target_and_reason_only(): void
    {
        $actor = $this->superAdmin();
        $target = $this->operations();

        $this->service()->resetPassword($actor, $target, self::TEMP, 'Locked out after device loss');

        $audit = AdminAuditLog::query()->where('event', AdminAuditLog::EVENT_PASSWORD_RESET)->sole();
        $this->assertSame($actor->id, $audit->actor_admin_id);
        $this->assertSame($target->id, $audit->target_admin_id);
        $this->assertSame(['reason' => 'Locked out after device loss'], $audit->metadata);
    }

    public function test_the_temporary_password_never_reaches_storage_audit_or_logs(): void
    {
        $canary = 'Canary-Secret-'.random_int(100000, 999999);
        $actor = $this->superAdmin();
        $target = $this->operations();

        $this->service()->resetPassword($actor, $target, $canary, 'Canary reset');

        // The admins table legitimately holds the target's hash, so the hash
        // check applies to the audit trail only. The plaintext must appear nowhere.
        $everything = json_encode([
            'admins' => DB::table('admins')->get()->all(),
            'audit' => DB::table('admin_audit_logs')->get()->all(),
        ]);
        $audit = (string) DB::table('admin_audit_logs')->get()->toJson();

        $this->assertStringNotContainsString($canary, $everything);
        $this->assertStringNotContainsString($canary, $audit);
        $this->assertStringNotContainsString($this->row($target)->password, $audit);
        $this->assertStringNotContainsString('$2y$', $audit);

        $logFile = storage_path('logs/laravel.log');
        if (is_file($logFile)) {
            $this->assertStringNotContainsString($canary, (string) file_get_contents($logFile));
        }
    }

    public function test_the_reset_form_never_shows_the_temporary_password_after_submission(): void
    {
        $actor = $this->superAdmin();
        $target = $this->operations();
        $this->actingAs($actor, 'admin');

        Livewire::test(ViewAdmin::class, ['record' => $target->id])
            ->callAction('resetPassword', data: [
                'temporary_password' => self::TEMP,
                'temporary_password_confirmation' => self::TEMP,
                'reason' => 'Routine reset',
            ])
            ->assertHasNoActionErrors();

        $this->get('/admin/admins/'.$target->id)->assertOk()->assertDontSee(self::TEMP, false);
    }

    public function test_reset_requires_a_strong_password_and_a_reason(): void
    {
        $actor = $this->superAdmin();
        $target = $this->operations();

        try {
            $this->service()->resetPassword($actor, $target, 'short', 'Reason here');
            $this->fail('A weak temporary password must be rejected.');
        } catch (ValidationException) {
        }

        $this->expectException(ValidationException::class);

        $this->service()->resetPassword($actor, $target, self::TEMP, '  ');
    }

    public function test_the_reset_form_requires_confirmation(): void
    {
        $actor = $this->superAdmin();
        $target = $this->operations();
        $this->actingAs($actor, 'admin');

        Livewire::test(ViewAdmin::class, ['record' => $target->id])
            ->callAction('resetPassword', data: [
                'temporary_password' => self::TEMP,
                'temporary_password_confirmation' => 'Different-Pass-99',
                'reason' => 'Routine reset',
            ])
            ->assertHasActionErrors(['temporary_password']);

        $this->assertFalse((bool) $this->row($target)->must_change_password);
    }

    // ---- Forced password change -------------------------------------

    public function test_a_reset_admin_can_sign_in_but_is_sent_to_the_password_change_page(): void
    {
        $target = $this->operations(['email' => 'forced@example.com']);
        $this->service()->resetPassword($this->superAdmin(), $target, self::TEMP, 'Reset for test');

        $this->signInThroughLoginPage('forced@example.com', self::TEMP)->assertHasNoFormErrors();
        $this->assertAuthenticatedAs($target->fresh(), 'admin');

        $this->get('/admin')->assertRedirect('/admin/change-admin-password');
    }

    public function test_a_forced_admin_cannot_reach_normal_admin_pages(): void
    {
        $target = $this->operations();
        $this->service()->resetPassword($this->superAdmin(), $target, self::TEMP, 'Reset for test');
        $this->actingAs($target->fresh(), 'admin');

        $this->get('/admin/admins')->assertRedirect('/admin/change-admin-password');
        $this->get('/admin/admins/'.$target->id)->assertRedirect('/admin/change-admin-password');
        $this->get('/admin/admins/create')->assertRedirect('/admin/change-admin-password');
    }

    public function test_a_forced_admin_is_refused_livewire_updates_to_any_other_page(): void
    {
        $target = $this->operations();
        $this->service()->resetPassword($this->superAdmin(), $target, self::TEMP, 'Reset for test');
        $this->actingAs($target->fresh(), 'admin');

        $payload = [
            'components' => [[
                'snapshot' => json_encode(['memo' => ['name' => ListAdmins::class, 'path' => 'admin/admins']]),
                'updates' => [],
                'calls' => [],
            ]],
        ];

        $this->withHeaders(['X-Livewire' => '1'])
            ->postJson(route('default-livewire.update'), $payload)
            ->assertForbidden();
    }

    public function test_the_middleware_lets_only_the_change_page_livewire_component_through_while_forced(): void
    {
        $target = $this->operations();
        $this->service()->resetPassword($this->superAdmin(), $target, self::TEMP, 'Reset for test');
        $this->actingAs($target->fresh(), 'admin');

        $middleware = app(EnforceAdminSession::class);

        $requestFor = fn (string $component) => Request::create('/admin/livewire/update', 'POST', [
            'components' => [[
                'snapshot' => json_encode(['memo' => ['name' => $component]]),
                'updates' => [],
                'calls' => [],
            ]],
        ], [], [], ['HTTP_X_LIVEWIRE' => '1']);

        $session = $this->app['session.store'];
        $session->put(Admin::SESSION_VERSION_KEY, (int) DB::table('admins')->where('id', $target->id)->value('session_version'));

        $pass = fn () => new Response('ok');

        $allowedRequest = $requestFor(ChangeAdminPassword::class);
        $allowedRequest->setLaravelSession($session);
        $deniedRequest = $requestFor(ListAdmins::class);
        $deniedRequest->setLaravelSession($session);

        $allowed = $middleware->handle($allowedRequest, $pass);
        $denied = $middleware->handle($deniedRequest, $pass);

        $this->assertSame(200, $allowed->getStatusCode());
        $this->assertSame(403, $denied->getStatusCode());
    }

    public function test_a_forced_admin_holds_no_ability_anywhere(): void
    {
        $target = $this->operations();
        $this->service()->resetPassword($this->superAdmin(), $target, self::TEMP, 'Reset for test');

        $forced = $target->fresh();
        foreach (AdminRole::cases() as $role) {
            $this->assertFalse($forced->hasAbility(AdminAbility::CustomerView));
        }
        $this->assertFalse($forced->hasAbility(AdminAbility::AdminManage));
        $this->assertFalse($forced->hasAbility(AdminAbility::AdminView));
    }

    public function test_the_forced_change_page_is_not_open_to_an_admin_who_is_not_forced(): void
    {
        $normal = $this->operations();
        $this->actingAs($normal, 'admin');

        $this->assertNotSame(200, $this->get('/admin/change-admin-password')->getStatusCode());
    }

    public function test_the_forced_change_page_is_open_to_a_forced_admin(): void
    {
        $forced = $this->operations();
        $this->service()->resetPassword($this->superAdmin(), $forced, self::TEMP, 'Reset for test');
        $this->actingAs($forced->fresh(), 'admin');

        $this->get('/admin/change-admin-password')->assertOk();
    }

    public function test_completing_the_forced_change_sets_a_new_password_and_clears_the_flag(): void
    {
        $target = $this->operations();
        $this->service()->resetPassword($this->superAdmin(), $target, self::TEMP, 'Reset for test');

        $this->service()->completeForcedPasswordChange($target->fresh(), self::TEMP, self::NEW);

        $row = $this->row($target);
        $this->assertFalse((bool) $row->must_change_password);
        $this->assertTrue(Hash::check(self::NEW, $row->password));
        $this->assertFalse(Hash::check(self::TEMP, $row->password));

        $audit = AdminAuditLog::query()->where('event', AdminAuditLog::EVENT_PASSWORD_CHANGED)->sole();
        $this->assertSame($target->id, $audit->actor_admin_id);
        $this->assertSame($target->id, $audit->target_admin_id);
        $this->assertNull($audit->metadata);
    }

    public function test_the_forced_change_requires_the_correct_current_password(): void
    {
        $target = $this->operations();
        $this->service()->resetPassword($this->superAdmin(), $target, self::TEMP, 'Reset for test');

        $this->expectException(ValidationException::class);

        $this->service()->completeForcedPasswordChange($target->fresh(), 'Wrong-Current-1', self::NEW);
    }

    public function test_the_forced_change_rejects_reusing_the_temporary_password(): void
    {
        $target = $this->operations();
        $this->service()->resetPassword($this->superAdmin(), $target, self::TEMP, 'Reset for test');

        $this->expectException(ValidationException::class);

        $this->service()->completeForcedPasswordChange($target->fresh(), self::TEMP, self::TEMP);
    }

    public function test_a_normal_admin_cannot_use_the_forced_change_operation(): void
    {
        $normal = $this->operations(['password' => 'Ops-Pass-12345']);

        $this->expectException(AdminManagementException::class);

        $this->service()->completeForcedPasswordChange($normal, 'Ops-Pass-12345', self::NEW);
    }

    public function test_after_the_forced_change_the_admin_signs_in_again_with_the_new_password(): void
    {
        $target = $this->operations(['email' => 'changer@example.com']);
        $this->service()->resetPassword($this->superAdmin(), $target, self::TEMP, 'Reset for test');
        $this->signInThroughLoginPage('changer@example.com', self::TEMP);
        $this->actingAs($target->fresh(), 'admin');

        Livewire::test(ChangeAdminPassword::class)
            ->callAction('changeOwnPassword', data: [
                'current_password' => self::TEMP,
                'password' => self::NEW,
                'password_confirmation' => self::NEW,
            ])
            ->assertHasNoActionErrors();

        $this->assertGuest('admin');

        $this->signInThroughLoginPage('changer@example.com', self::NEW)->assertHasNoFormErrors();
        $this->assertAuthenticatedAs($target->fresh(), 'admin');
        $this->get('/admin')->assertOk();
    }

    // ---- Session generation -----------------------------------------

    public function test_a_session_from_before_a_password_reset_is_rejected(): void
    {
        $target = $this->operations();
        $this->actingAs($target, 'admin');
        $this->get('/admin')->assertOk();

        $this->service()->resetPassword($this->superAdmin(), $target, self::TEMP, 'Reset for test');

        $this->get('/admin')->assertRedirect('/admin/login');
        $this->assertGuest('admin');
    }

    public function test_a_session_from_before_a_password_change_is_rejected(): void
    {
        $target = $this->operations();
        $this->service()->resetPassword($this->superAdmin(), $target, self::TEMP, 'Reset for test');
        $this->actingAs($target->fresh(), 'admin');

        $this->service()->completeForcedPasswordChange($target->fresh(), self::TEMP, self::NEW);

        $this->get('/admin/change-admin-password')->assertRedirect('/admin/login');
    }

    public function test_deactivation_invalidates_an_existing_session(): void
    {
        $actor = $this->superAdmin();
        $target = $this->operations();
        $this->actingAs($target, 'admin');
        $this->get('/admin')->assertOk();

        $this->service()->deactivate($actor, $target, 'Left the company');

        $this->get('/admin')->assertForbidden();
    }

    public function test_reactivation_does_not_revive_a_session_from_before_deactivation(): void
    {
        $actor = $this->superAdmin();
        $target = $this->operations();
        $this->actingAs($target, 'admin');
        $this->get('/admin')->assertOk();

        $this->service()->deactivate($actor, $target, 'Temporary leave');
        $this->service()->activate($actor, $target, 'Returned');

        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_a_session_without_a_version_stamp_is_refused(): void
    {
        $target = $this->operations();

        // be() is Laravel's own helper: it sets the user but not the stamp
        // that a real sign-in writes. Such a session must not work.
        $this->be($target, 'admin');

        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_a_revoked_session_is_refused_livewire_updates_as_well(): void
    {
        $target = $this->operations();
        $this->actingAs($target, 'admin');
        $this->service()->resetPassword($this->superAdmin(), $target, self::TEMP, 'Reset for test');

        $this->withHeaders(['X-Livewire' => '1'])
            ->postJson(route('default-livewire.update'), [
                'components' => [[
                    'snapshot' => json_encode(['memo' => ['name' => ListAdmins::class]]),
                    'updates' => [],
                    'calls' => [],
                ]],
            ])
            ->assertStatus(401);
    }

    public function test_a_fresh_sign_in_after_revocation_works(): void
    {
        $target = $this->operations(['email' => 'fresh@example.com']);
        $this->service()->resetPassword($this->superAdmin(), $target, self::TEMP, 'Reset for test');

        $this->signInThroughLoginPage('fresh@example.com', self::TEMP)->assertHasNoFormErrors();
        $this->assertAuthenticatedAs($target->fresh(), 'admin');
    }

    // ---- last_login_at ----------------------------------------------

    public function test_a_successful_sign_in_records_last_login_at(): void
    {
        $admin = $this->operations(['email' => 'login@example.com', 'password' => 'Login-Pass-123']);
        $this->assertNull($this->row($admin)->last_login_at);

        $this->signInThroughLoginPage('login@example.com', 'Login-Pass-123')->assertHasNoFormErrors();

        $this->assertNotNull($this->row($admin)->last_login_at);
        $this->assertEqualsWithDelta(now()->timestamp, strtotime($this->row($admin)->last_login_at), 60);
    }

    public function test_a_failed_sign_in_does_not_record_last_login_at(): void
    {
        $admin = $this->operations(['email' => 'failed@example.com', 'password' => 'Login-Pass-123']);

        $this->signInThroughLoginPage('failed@example.com', 'Wrong-Pass-999')->assertHasFormErrors();

        $this->assertNull($this->row($admin)->last_login_at);
    }

    public function test_an_inactive_admin_cannot_sign_in_and_gets_no_last_login(): void
    {
        $admin = $this->operations(['email' => 'off@example.com', 'password' => 'Login-Pass-123', 'is_active' => false]);

        $this->signInThroughLoginPage('off@example.com', 'Login-Pass-123')->assertHasFormErrors();

        $this->assertGuest('admin');
        $this->assertNull($this->row($admin)->last_login_at);
    }

    // ---- Activation and deactivation reasons ------------------------

    public function test_activation_requires_a_reason(): void
    {
        $target = $this->operations(['is_active' => false]);

        $this->expectException(ValidationException::class);

        $this->service()->activate($this->superAdmin(), $target, '   ');
    }

    public function test_the_activation_reason_is_recorded_in_the_audit_metadata(): void
    {
        $actor = $this->superAdmin();
        $target = $this->operations(['is_active' => false]);

        $this->service()->activate($actor, $target, 'Returned from leave');

        $audit = AdminAuditLog::query()->where('event', AdminAuditLog::EVENT_ACTIVATED)->sole();
        $this->assertSame(['reason' => 'Returned from leave'], $audit->metadata);
    }

    public function test_deactivation_requires_a_reason(): void
    {
        $target = $this->operations();

        $this->expectException(ValidationException::class);

        $this->service()->deactivate($this->superAdmin(), $target, '');
    }

    public function test_a_role_change_requires_a_reason(): void
    {
        $target = $this->operations();

        $this->expectException(ValidationException::class);

        $this->service()->changeRole($this->superAdmin(), $target, AdminRole::SuperAdmin, 'x');
    }

    public function test_the_activation_form_requires_a_reason(): void
    {
        $actor = $this->superAdmin();
        $target = $this->operations(['is_active' => false]);
        $this->actingAs($actor, 'admin');

        Livewire::test(ViewAdmin::class, ['record' => $target->id])
            ->callAction('activate', data: ['reason' => ''])
            ->assertHasActionErrors(['reason']);

        $this->assertFalse((bool) $this->row($target)->is_active);
    }

    // ---- Self-management --------------------------------------------

    public function test_self_deactivation_and_self_demotion_stay_denied(): void
    {
        $self = $this->superAdmin();
        $this->superAdmin();

        try {
            $this->service()->deactivate($self, $self, 'Self-service attempt');
            $this->fail('Self-deactivation must be refused.');
        } catch (AdminManagementException) {
        }

        try {
            $this->service()->changeRole($self, $self, AdminRole::Operations, 'Self-service attempt');
            $this->fail('Self-demotion must be refused.');
        } catch (AdminManagementException) {
        }

        $this->assertTrue((bool) $this->row($self)->is_active);
        $this->assertSame('super_admin', $this->row($self)->role);
    }

    public function test_self_name_and_email_update_is_still_allowed(): void
    {
        $self = $this->superAdmin();

        $this->service()->updateProfile($self, $self, ['name' => 'Renamed', 'email' => 'renamed.self@example.com']);

        $this->assertSame('Renamed', $this->row($self)->name);
    }

    public function test_profile_update_cannot_set_the_security_columns(): void
    {
        $self = $this->superAdmin();
        $target = $this->operations();

        $this->service()->updateProfile($this->superAdmin(), $target, [
            'name' => $target->name,
            'email' => $target->email,
            'must_change_password' => true,
            'session_version' => 99,
        ]);

        $this->assertFalse((bool) $this->row($target)->must_change_password);
        $this->assertSame(1, (int) $this->row($target)->session_version);
    }

    // ---- Final SuperAdmin still protected ---------------------------

    public function test_the_final_active_super_admin_still_cannot_be_deactivated_or_demoted(): void
    {
        $sole = $this->superAdmin();
        $operations = $this->operations();
        Gate::policy(Admin::class, PermissiveResetPolicy::class);
        PermissiveResetPolicy::$allowedActorId = $operations->id;

        try {
            $this->service()->deactivate($operations, $sole, 'Attempt');
            $this->fail('The final active SuperAdmin must not be deactivated.');
        } catch (AdminManagementException) {
        }

        try {
            $this->service()->changeRole($operations, $sole, AdminRole::Operations, 'Attempt');
            $this->fail('The final active SuperAdmin must not be demoted.');
        } catch (AdminManagementException) {
        }

        $this->assertTrue((bool) $this->row($sole)->is_active);
        $this->assertSame('super_admin', $this->row($sole)->role);
    }
}

/**
 * Test-only: grants AdminManage-level decisions to one actor, so the domain
 * invariant is exercised on its own.
 */
class PermissiveResetPolicy extends AdminPolicy
{
    public static ?int $allowedActorId = null;

    public function deactivate(Admin $admin, Admin $target): bool
    {
        return $admin->getKey() === self::$allowedActorId || parent::deactivate($admin, $target);
    }

    public function updateRole(Admin $admin, Admin $target): bool
    {
        return $admin->getKey() === self::$allowedActorId || parent::updateRole($admin, $target);
    }
}
