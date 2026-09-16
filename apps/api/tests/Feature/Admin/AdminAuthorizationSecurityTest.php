<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminAbility;
use App\Models\Admin;
use App\Models\Competition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Negative/isolation tests for the P11.2 authorization layer (mandatory
 * per the P11.2 spec — allowed-path coverage alone is not enough).
 * Complements the guard-level isolation already proven in P11.1's
 * AdminGuardIsolationTest by proving the *authorization* layer built on
 * top of that guard also fails closed: a customer or guest identity
 * must never satisfy an admin ability check, an inactive admin must
 * lose every ability immediately, and admin account management is
 * denied to Operations across every distinct action.
 */
class AdminAuthorizationSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_identity_is_denied_every_admin_ability(): void
    {
        $customer = User::factory()->create();

        $this->assertFalse($customer->can('viewAny', Competition::class));
        $this->assertFalse($customer->can('viewAny', Admin::class));
    }

    public function test_unauthenticated_request_is_denied_admin_authorization(): void
    {
        $this->assertGuest();

        $this->assertFalse(Gate::allows('viewAny', Competition::class));
        $this->assertFalse(Gate::allows('viewAny', Admin::class));
    }

    public function test_inactive_admin_is_denied_despite_holding_the_role(): void
    {
        $inactive = Admin::factory()->inactive()->create(); // Operations by default

        $this->assertFalse($inactive->hasAbility(AdminAbility::CompetitionView));
        $this->assertFalse($inactive->can('viewAny', Competition::class));

        $inactiveSuperAdmin = Admin::factory()->superAdmin()->inactive()->create();
        $this->assertFalse($inactiveSuperAdmin->hasAbility(AdminAbility::AdminManage));
        $this->assertFalse($inactiveSuperAdmin->can('create', Admin::class));
    }

    /**
     * AdminRole is a strict PHP backed enum — an admin row whose `role`
     * column holds a value outside {super_admin, operations} (only
     * reachable by bypassing Eloquent, e.g. a raw SQL update) fails
     * *closed*: accessing ->role throws \ValueError instead of
     * defaulting to any ability, so it can never silently resolve to
     * "most privileged" or "least privileged". This is deliberately not
     * caught and coerced into a boolean — see the P11.2 report's known
     * limitations for why.
     */
    public function test_admin_with_an_invalid_role_value_fails_closed(): void
    {
        $admin = Admin::factory()->create();

        DB::table('admins')->where('id', $admin->id)->update(['role' => 'not_a_real_role']);

        $reloaded = Admin::find($admin->id);

        $this->expectException(\ValueError::class);
        $reloaded->hasAbility(AdminAbility::CompetitionView);
    }

    #[DataProvider('adminManagementActionProvider')]
    public function test_operations_is_denied_every_admin_management_action(string $ability, bool $needsTargetInstance): void
    {
        $operations = Admin::factory()->create();
        $target = Admin::factory()->create();

        $subject = $needsTargetInstance ? $target : Admin::class;

        $this->assertFalse($operations->can($ability, $subject));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function adminManagementActionProvider(): array
    {
        return [
            'viewAny' => ['viewAny', false],
            'view' => ['view', true],
            'create' => ['create', false],
            'update' => ['update', true],
            'updateRole' => ['updateRole', true],
            'activate' => ['activate', true],
            'deactivate' => ['deactivate', true],
        ];
    }

    public function test_super_admin_is_granted_every_admin_management_action(): void
    {
        $superAdmin = Admin::factory()->superAdmin()->create();
        $target = Admin::factory()->create();

        $this->assertTrue($superAdmin->can('viewAny', Admin::class));
        $this->assertTrue($superAdmin->can('view', $target));
        $this->assertTrue($superAdmin->can('create', Admin::class));
        $this->assertTrue($superAdmin->can('update', $target));
        $this->assertTrue($superAdmin->can('updateRole', $target));
        $this->assertTrue($superAdmin->can('activate', $target));
        $this->assertTrue($superAdmin->can('deactivate', $target));
    }
}
