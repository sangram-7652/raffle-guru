<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminAbility;
use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Policies\AdminPolicy;
use App\Services\Admin\AdminManagementException;
use App\Services\Admin\AdminManagementService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

/**
 * P11.12 — AdminManagementService: the domain rules, independent of any
 * Filament screen. Every refusal is asserted here so it holds for crafted
 * requests as well as the UI.
 */
class AdminManagementServiceTest extends TestCase
{
    use RefreshDatabase;

    private AdminManagementService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(AdminManagementService::class);
    }

    private function superAdmin(array $attributes = []): Admin
    {
        return Admin::factory()->superAdmin()->create($attributes);
    }

    private function operations(array $attributes = []): Admin
    {
        return Admin::factory()->create($attributes);
    }

    private function validCreateData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'New Admin',
            'email' => 'new.admin@example.com',
            'role' => AdminRole::Operations->value,
            'password' => 'Correct-Horse-9',
            'is_active' => true,
        ], $overrides);
    }

    // ---- Create -----------------------------------------------------

    public function test_super_admin_creates_an_admin_with_a_hashed_password(): void
    {
        $actor = $this->superAdmin();

        $created = $this->service->create($actor, $this->validCreateData());

        $this->assertSame('new.admin@example.com', $created->email);
        $this->assertSame(AdminRole::Operations, $created->role);
        $this->assertTrue($created->is_active);

        $stored = DB::table('admins')->where('id', $created->id)->value('password');
        $this->assertNotSame('Correct-Horse-9', $stored);
        $this->assertTrue(Hash::check('Correct-Horse-9', $stored));

        $audit = AdminAuditLog::query()->where('target_admin_id', $created->id)->sole();
        $this->assertSame(AdminAuditLog::EVENT_CREATED, $audit->event);
        $this->assertSame($actor->id, $audit->actor_admin_id);
        $this->assertEquals(['role' => 'operations', 'is_active' => true], $audit->metadata);
    }

    public function test_duplicate_email_is_rejected_case_insensitively(): void
    {
        $actor = $this->superAdmin();
        $this->operations(['email' => 'taken@example.com']);

        $this->expectException(ValidationException::class);

        try {
            $this->service->create($actor, $this->validCreateData(['email' => 'TAKEN@example.com']));
        } finally {
            $this->assertSame(1, Admin::query()->where('email', 'taken@example.com')->count());
        }
    }

    public function test_an_invalid_role_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->create($this->superAdmin(), $this->validCreateData(['role' => 'owner']));
    }

    public function test_a_weak_password_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->create($this->superAdmin(), $this->validCreateData(['password' => 'short']));
    }

    public function test_operations_cannot_create_admins(): void
    {
        $this->expectException(AuthorizationException::class);

        try {
            $this->service->create($this->operations(), $this->validCreateData());
        } finally {
            $this->assertSame(0, Admin::query()->where('email', 'new.admin@example.com')->count());
        }
    }

    public function test_mass_assignment_of_protected_keys_is_ignored_on_create(): void
    {
        $created = $this->service->create($this->superAdmin(), $this->validCreateData([
            'id' => 9999,
            'email_verified_at' => null,
            'last_login_at' => now()->subYear(),
        ]));

        $this->assertNotSame(9999, $created->id);
        $this->assertNull($created->last_login_at);
    }

    // ---- Update (safe fields only) ----------------------------------

    public function test_name_and_email_update_is_audited_with_field_names_only(): void
    {
        $actor = $this->superAdmin();
        $target = $this->operations(['name' => 'Old Name', 'email' => 'old@example.com']);

        $this->service->updateProfile($actor, $target, ['name' => 'New Name', 'email' => 'new@example.com']);

        $this->assertSame('New Name', $target->fresh()->name);
        $audit = AdminAuditLog::query()->where('target_admin_id', $target->id)->sole();
        $this->assertSame(AdminAuditLog::EVENT_UPDATED, $audit->event);
        $this->assertEquals(['changed' => ['name', 'email']], $audit->metadata);
        $this->assertStringNotContainsString('old@example.com', json_encode($audit->metadata));
    }

    public function test_an_unchanged_update_writes_nothing_and_audits_nothing(): void
    {
        $actor = $this->superAdmin();
        $target = $this->operations(['name' => 'Same', 'email' => 'same@example.com']);

        $this->service->updateProfile($actor, $target, ['name' => 'Same', 'email' => 'same@example.com']);

        $this->assertSame(0, AdminAuditLog::query()->where('target_admin_id', $target->id)->count());
    }

    public function test_updating_to_another_admins_email_is_rejected(): void
    {
        $actor = $this->superAdmin();
        $this->operations(['email' => 'other@example.com']);
        $target = $this->operations(['email' => 'mine@example.com']);

        $this->expectException(ValidationException::class);

        $this->service->updateProfile($actor, $target, ['name' => $target->name, 'email' => 'other@example.com']);
    }

    public function test_update_ignores_role_status_and_password_fields(): void
    {
        $actor = $this->superAdmin();
        $target = $this->operations();
        $hashBefore = DB::table('admins')->where('id', $target->id)->value('password');

        // The service only takes name and email, whatever the caller passes.
        $this->service->updateProfile($actor, $target, [
            'name' => 'Renamed',
            'email' => $target->email,
            'role' => 'super_admin',
            'is_active' => false,
            'password' => 'Hijacked-Pass-1',
        ]);

        $fresh = $target->fresh();
        $this->assertSame(AdminRole::Operations, $fresh->role);
        $this->assertTrue($fresh->is_active);
        $this->assertSame($hashBefore, DB::table('admins')->where('id', $target->id)->value('password'));
    }

    public function test_operations_cannot_update_admins(): void
    {
        $target = $this->operations(['name' => 'Original']);

        $this->expectException(AuthorizationException::class);

        try {
            $this->service->updateProfile($this->operations(), $target, ['name' => 'Changed', 'email' => $target->email]);
        } finally {
            $this->assertSame('Original', $target->fresh()->name);
        }
    }

    // ---- Role change ------------------------------------------------

    public function test_super_admin_changes_another_admins_role_and_it_is_audited(): void
    {
        $actor = $this->superAdmin();
        $target = $this->operations();

        $this->service->changeRole($actor, $target, AdminRole::SuperAdmin, 'Promoted to cover on-call');

        $this->assertSame(AdminRole::SuperAdmin, $target->fresh()->role);
        $audit = AdminAuditLog::query()->where('target_admin_id', $target->id)->sole();
        $this->assertSame(AdminAuditLog::EVENT_ROLE_CHANGED, $audit->event);
        $this->assertSame($actor->id, $audit->actor_admin_id);
        $this->assertEquals(['from' => 'operations', 'to' => 'super_admin', 'reason' => 'Promoted to cover on-call'], $audit->metadata);
    }

    public function test_role_change_takes_effect_immediately_on_the_abilities_matrix(): void
    {
        $actor = $this->superAdmin();
        $target = $this->operations();
        $this->assertFalse($target->hasAbility(AdminAbility::AdminView));

        $this->service->changeRole($actor, $target, AdminRole::SuperAdmin, 'Promotion for testing');

        $this->assertTrue($target->fresh()->hasAbility(AdminAbility::AdminView));
    }

    public function test_the_same_role_is_refused(): void
    {
        $this->expectException(AdminManagementException::class);

        $this->service->changeRole($this->superAdmin(), $this->operations(), AdminRole::Operations, 'No-op change');
    }

    public function test_a_short_or_empty_reason_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->changeRole($this->superAdmin(), $this->operations(), AdminRole::SuperAdmin, '   ');
    }

    public function test_operations_cannot_change_roles(): void
    {
        $target = $this->operations();

        $this->expectException(AuthorizationException::class);

        try {
            $this->service->changeRole($this->operations(), $target, AdminRole::SuperAdmin, 'Escalation attempt');
        } finally {
            $this->assertSame(AdminRole::Operations, $target->fresh()->role);
        }
    }

    // ---- Activation and deactivation --------------------------------

    public function test_deactivation_removes_panel_access_and_abilities_immediately(): void
    {
        $actor = $this->superAdmin();
        $target = $this->operations();
        $this->assertTrue($target->hasAbility(AdminAbility::CustomerView));

        $this->service->deactivate($actor, $target, 'Left the company');

        $fresh = $target->fresh();
        $this->assertFalse($fresh->is_active);
        $this->assertFalse($fresh->hasAbility(AdminAbility::CompetitionView));
        $audit = AdminAuditLog::query()->where('target_admin_id', $target->id)->sole();
        $this->assertSame(AdminAuditLog::EVENT_DEACTIVATED, $audit->event);
        $this->assertEquals(['reason' => 'Left the company'], $audit->metadata);
    }

    public function test_activation_restores_access_and_is_audited(): void
    {
        $actor = $this->superAdmin();
        $target = $this->operations(['is_active' => false]);

        $this->service->activate($actor, $target, 'Returned from leave');

        $this->assertTrue($target->fresh()->is_active);
        $audit = AdminAuditLog::query()->where('target_admin_id', $target->id)->sole();
        $this->assertSame(AdminAuditLog::EVENT_ACTIVATED, $audit->event);
        $this->assertEquals(['reason' => 'Returned from leave'], $audit->metadata);
    }

    public function test_activating_an_already_active_admin_is_refused(): void
    {
        $this->expectException(AdminManagementException::class);

        $this->service->activate($this->superAdmin(), $this->operations(), 'Already on');
    }

    public function test_deactivating_an_already_inactive_admin_is_refused(): void
    {
        $this->expectException(AdminManagementException::class);

        $this->service->deactivate($this->superAdmin(), $this->operations(['is_active' => false]), 'Already off');
    }

    public function test_operations_cannot_deactivate_or_activate(): void
    {
        $active = $this->operations();
        $inactive = $this->operations(['is_active' => false]);

        try {
            $this->service->deactivate($this->operations(), $active, 'Attempt');
            $this->fail('Operations must not deactivate.');
        } catch (AuthorizationException) {
        }

        try {
            $this->service->activate($this->operations(), $inactive, 'Attempt without rights');
            $this->fail('Operations must not activate.');
        } catch (AuthorizationException) {
        }

        $this->assertTrue($active->fresh()->is_active);
        $this->assertFalse($inactive->fresh()->is_active);
    }

    // ---- Final-SuperAdmin invariant ---------------------------------

    /**
     * Under the real policy only an active SuperAdmin can manage admins, so
     * the invariant guard is defence in depth for sequential calls and the
     * first line of defence only under races (see the MySQL concurrency
     * test). To test the guard itself, these cases grant AdminManage to an
     * Operations actor and assert the DOMAIN refuses, not the policy.
     */
    private function permitAdminManagementFor(Admin $actor): void
    {
        Gate::policy(Admin::class, PermissiveAdminPolicy::class);
        PermissiveAdminPolicy::$allowedActorId = $actor->id;
    }

    public function test_the_final_active_super_admin_cannot_be_deactivated(): void
    {
        $finalSuperAdmin = $this->superAdmin();
        $operations = $this->operations();
        $this->permitAdminManagementFor($operations);

        $this->expectException(AdminManagementException::class);
        $this->expectExceptionMessage('no active Super Admin');

        try {
            $this->service->deactivate($operations, $finalSuperAdmin, 'Attempt to remove the last one');
        } finally {
            $this->assertTrue($finalSuperAdmin->fresh()->is_active);
        }
    }

    public function test_the_final_active_super_admin_cannot_be_demoted(): void
    {
        $finalSuperAdmin = $this->superAdmin();
        $operations = $this->operations();
        $this->permitAdminManagementFor($operations);

        $this->expectException(AdminManagementException::class);
        $this->expectExceptionMessage('no active Super Admin');

        try {
            $this->service->changeRole($operations, $finalSuperAdmin, AdminRole::Operations, 'Attempt to demote the last one');
        } finally {
            $this->assertSame(AdminRole::SuperAdmin, $finalSuperAdmin->fresh()->role);
        }
    }

    public function test_an_inactive_super_admin_does_not_count_towards_the_invariant(): void
    {
        $finalActive = $this->superAdmin();
        $this->superAdmin(['is_active' => false]);
        $operations = $this->operations();
        $this->permitAdminManagementFor($operations);

        // The only OTHER SuperAdmin is inactive, so $finalActive is the last active one.
        $this->expectException(AdminManagementException::class);

        $this->service->deactivate($operations, $finalActive, 'Attempt');
    }

    public function test_demoting_an_inactive_super_admin_is_allowed_when_another_active_one_exists(): void
    {
        $active = $this->superAdmin();
        $inactive = $this->superAdmin(['is_active' => false]);

        $this->service->changeRole($active, $inactive, AdminRole::Operations, 'Tidy up dormant account');

        $this->assertSame(AdminRole::Operations, $inactive->fresh()->role);
        $this->assertTrue($active->fresh()->is_active);
    }

    public function test_a_super_admin_can_be_deactivated_when_another_active_super_admin_exists(): void
    {
        $actor = $this->superAdmin();
        $other = $this->superAdmin();

        $this->service->deactivate($actor, $other, 'Routine');

        $this->assertFalse($other->fresh()->is_active);
        $this->assertTrue($actor->fresh()->is_active);
    }

    // ---- Self-management --------------------------------------------

    public function test_an_admin_cannot_deactivate_themselves_even_with_other_super_admins(): void
    {
        $self = $this->superAdmin();
        $this->superAdmin();

        $this->expectException(AdminManagementException::class);
        $this->expectExceptionMessage('own account');

        $this->service->deactivate($self, $self, 'Self-service');
    }

    public function test_an_admin_cannot_demote_themselves_even_with_other_super_admins(): void
    {
        $self = $this->superAdmin();
        $this->superAdmin();

        $this->expectException(AdminManagementException::class);
        $this->expectExceptionMessage('own role');

        $this->service->changeRole($self, $self, AdminRole::Operations, 'Self-demotion');
    }

    public function test_an_admin_can_update_their_own_name_and_email(): void
    {
        $self = $this->superAdmin();

        $this->service->updateProfile($self, $self, ['name' => 'Renamed Self', 'email' => 'renamed@example.com']);

        $this->assertSame('Renamed Self', $self->fresh()->name);
        $this->assertSame(AdminRole::SuperAdmin, $self->fresh()->role);
    }

    // ---- Stale actor state ------------------------------------------

    public function test_an_actor_deactivated_by_someone_else_is_refused_even_with_a_stale_object(): void
    {
        $staleActor = $this->superAdmin();
        $otherSuperAdmin = $this->superAdmin();
        $target = $this->operations();

        // Another SuperAdmin deactivates the first one. $staleActor still
        // holds the in-memory state from before that happened.
        $this->service->deactivate($otherSuperAdmin, $staleActor, 'Revoked');
        $this->assertTrue($staleActor->is_active);

        $this->expectException(AuthorizationException::class);

        $this->service->changeRole($staleActor, $target, AdminRole::SuperAdmin, 'Stale attempt');
    }

    // ---- Audit immutability -----------------------------------------

    public function test_audit_records_cannot_be_updated_or_deleted(): void
    {
        $this->service->deactivate($this->superAdmin(), $this->operations(), 'Setup row');
        $log = AdminAuditLog::query()->sole();

        try {
            $log->update(['event' => 'admin.tampered']);
            $this->fail('Audit rows must be immutable.');
        } catch (LogicException) {
        }

        try {
            $log->delete();
            $this->fail('Audit rows must not be deletable.');
        } catch (LogicException) {
        }

        $this->assertSame(AdminAuditLog::EVENT_DEACTIVATED, $log->fresh()->event);
    }

    public function test_audit_metadata_never_contains_password_hashes_or_plaintext(): void
    {
        $actor = $this->superAdmin();
        $created = $this->service->create($actor, $this->validCreateData([
            'email' => 'audit-check@example.com',
            'password' => 'Plaintext-Canary-42',
        ]));
        $hash = DB::table('admins')->where('id', $created->id)->value('password');
        $this->service->changeRole($actor, $created, AdminRole::SuperAdmin, 'Promotion reason');
        $this->service->deactivate($actor, $created, 'Deactivation reason');

        $dump = json_encode(DB::table('admin_audit_logs')->get()->all());

        $this->assertStringNotContainsString('Plaintext-Canary-42', $dump);
        $this->assertStringNotContainsString($hash, $dump);
        $this->assertStringNotContainsString('$2y$', $dump);
        $this->assertStringNotContainsString('remember_token', $dump);
    }

    public function test_every_mutation_writes_exactly_one_audit_row_with_the_correct_actor_and_target(): void
    {
        $actor = $this->superAdmin();
        $target = $this->operations();

        $this->service->updateProfile($actor, $target, ['name' => 'Changed', 'email' => $target->email]);
        $this->service->changeRole($actor, $target, AdminRole::SuperAdmin, 'Promotion reason');
        $this->service->deactivate($actor, $target, 'Off reason');
        $this->service->activate($actor, $target, 'Reinstated');

        $logs = AdminAuditLog::query()->where('target_admin_id', $target->id)->orderBy('id')->get();
        $this->assertSame(
            [AdminAuditLog::EVENT_UPDATED, AdminAuditLog::EVENT_ROLE_CHANGED, AdminAuditLog::EVENT_DEACTIVATED, AdminAuditLog::EVENT_ACTIVATED],
            $logs->pluck('event')->all(),
        );
        $this->assertTrue($logs->every(fn (AdminAuditLog $log): bool => $log->actor_admin_id === $actor->id));
    }

    public function test_a_refused_operation_writes_no_audit_row_and_changes_nothing(): void
    {
        $self = $this->superAdmin();
        $this->superAdmin();

        try {
            $this->service->deactivate($self, $self, 'Refused');
        } catch (AdminManagementException) {
        }

        $this->assertTrue($self->fresh()->is_active);
        $this->assertSame(0, AdminAuditLog::query()->count());
    }
}

/**
 * Test-only: grants AdminManage-level decisions to one named actor so the
 * domain invariant can be exercised without an active SuperAdmin acting.
 */
class PermissiveAdminPolicy extends AdminPolicy
{
    public static ?int $allowedActorId = null;

    private function allowed(Admin $admin): bool
    {
        return $admin->getKey() === self::$allowedActorId;
    }

    public function updateRole(Admin $admin, Admin $target): bool
    {
        return $this->allowed($admin) || parent::updateRole($admin, $target);
    }

    public function deactivate(Admin $admin, Admin $target): bool
    {
        return $this->allowed($admin) || parent::deactivate($admin, $target);
    }
}
