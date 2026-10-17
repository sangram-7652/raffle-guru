<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminAbility;
use App\Enums\AdminRole;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Table-driven coverage of the P11.2 role → ability matrix
 * (AdminRole::abilities(), consumed via Admin::hasAbility()). This is
 * the single source of truth every Policy in app/Policies is built on
 * — asserting it exactly, ability by ability, for both roles is what
 * makes the whole authorization surface reviewable from one file
 * instead of having to read every policy to know what each role can
 * do. Policy-level wiring (Gate resolution, the customer/guest guard)
 * is covered separately in AdminPolicyGateIntegrationTest and
 * AdminAuthorizationSecurityTest.
 */
class AdminAbilityMatrixTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The approved P11.2 role matrix, spelled out explicitly so a
     * diff against this array is a diff against the actual product
     * decision, not just against whatever AdminRole::abilities()
     * happens to return today.
     *
     * @return array<string, bool>
     */
    private static function operationsExpectedGrants(): array
    {
        $granted = [
            AdminAbility::CompetitionView,
            AdminAbility::CompetitionCreate,
            AdminAbility::CompetitionUpdate,
            AdminAbility::CompetitionLifecycle,
            AdminAbility::PrizeView,
            AdminAbility::PrizeCreate,
            AdminAbility::PrizeUpdate,
            AdminAbility::PrizeDelete,
            AdminAbility::OrderView,
            AdminAbility::PaymentView,
            AdminAbility::EntryView,
            AdminAbility::TicketView,
            AdminAbility::DrawView,
            AdminAbility::DrawRun,
            AdminAbility::WinnerView,
            AdminAbility::WinnerTransition,
            AdminAbility::ClaimView,
            AdminAbility::ClaimVerify,
            AdminAbility::ClaimApprove,
            AdminAbility::ClaimReject,
            AdminAbility::FulfilmentView,
            AdminAbility::FulfilmentAdvance,
            AdminAbility::FulfilmentAssignPrize,
            AdminAbility::CustomerView,
            // Promotion.view only, not .manage — see
            // docs/EMAIL_CAPTURE.md "Which admin role(s) should be
            // able to create/enable/disable a promotion...".
            AdminAbility::PromotionView,
        ];

        $expectations = [];
        foreach (AdminAbility::cases() as $ability) {
            $expectations[$ability->value] = in_array($ability, $granted, true);
        }

        return $expectations;
    }

    public function test_super_admin_holds_every_ability(): void
    {
        $superAdmin = Admin::factory()->superAdmin()->create();

        foreach (AdminAbility::cases() as $ability) {
            $this->assertTrue(
                $superAdmin->hasAbility($ability),
                "Super Admin is expected to hold {$ability->value} but does not."
            );
        }
    }

    #[DataProvider('operationsAbilityProvider')]
    public function test_operations_ability_matches_the_approved_matrix(AdminAbility $ability, bool $expectedGranted): void
    {
        $operations = Admin::factory()->create(); // default role is Operations

        $this->assertSame(
            $expectedGranted,
            $operations->hasAbility($ability),
            "Operations ability mismatch for {$ability->value}: expected ".($expectedGranted ? 'granted' : 'denied').'.'
        );
    }

    /**
     * @return array<string, array{0: AdminAbility, 1: bool}>
     */
    public static function operationsAbilityProvider(): array
    {
        $expectations = self::operationsExpectedGrants();

        $cases = [];
        foreach (AdminAbility::cases() as $ability) {
            $cases[$ability->value] = [$ability, $expectations[$ability->value]];
        }

        return $cases;
    }

    public function test_operations_explicitly_lacks_admin_and_audit_abilities(): void
    {
        $operations = Admin::factory()->create();

        $this->assertFalse($operations->hasAbility(AdminAbility::AdminView));
        $this->assertFalse($operations->hasAbility(AdminAbility::AdminManage));
        $this->assertFalse($operations->hasAbility(AdminAbility::AuditView));
        $this->assertFalse($operations->hasAbility(AdminAbility::CompetitionDelete));
        $this->assertFalse($operations->hasAbility(AdminAbility::PromotionManage));
    }

    public function test_role_abilities_are_fixed_enum_lists_not_role_flags(): void
    {
        $this->assertContainsOnlyInstancesOf(AdminAbility::class, AdminRole::SuperAdmin->abilities());
        $this->assertContainsOnlyInstancesOf(AdminAbility::class, AdminRole::Operations->abilities());
        $this->assertCount(count(AdminAbility::cases()), AdminRole::SuperAdmin->abilities());
    }
}
