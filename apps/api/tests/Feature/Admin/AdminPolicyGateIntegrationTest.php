<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\Competition;
use App\Models\Draw;
use App\Models\DrawAuditLog;
use App\Models\Entry;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Prize;
use App\Models\PrizeDelivery;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Winner;
use App\Models\WinnerAuditLog;
use App\Models\WinnerClaim;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proves each Policy in app/Policies is actually wired up and reachable
 * through Laravel's Gate — not just that AdminRole::abilities() returns
 * the right array (see AdminAbilityMatrixTest). Exercises real
 * `$admin->can()`/`cannot()` calls, which is what Filament itself will
 * call once resources exist (P11.2 §11), so this is what proves policy
 * auto-discovery (App\Models\X -> App\Policies\XPolicy) actually
 * resolves rather than silently falling through to Gate's default
 * "no policy found" allow-nothing behavior.
 */
class AdminPolicyGateIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_competition_policy_distinguishes_operations_from_super_admin(): void
    {
        $operations = Admin::factory()->create();
        $superAdmin = Admin::factory()->superAdmin()->create();
        $competition = Competition::factory()->create();

        $this->assertTrue($operations->can('viewAny', Competition::class));
        $this->assertTrue($operations->can('create', Competition::class));
        $this->assertTrue($operations->can('update', $competition));
        $this->assertTrue($operations->can('transitionLifecycle', $competition));
        // The one deliberate asymmetry in the matrix: Operations gets
        // view/create/update but not delete.
        $this->assertFalse($operations->can('delete', $competition));

        $this->assertTrue($superAdmin->can('delete', $competition));
    }

    public function test_prize_policy_grants_full_crud_to_both_roles(): void
    {
        $operations = Admin::factory()->create();
        $prize = Prize::factory()->create();

        $this->assertTrue($operations->can('viewAny', Prize::class));
        $this->assertTrue($operations->can('create', Prize::class));
        $this->assertTrue($operations->can('update', $prize));
        $this->assertTrue($operations->can('delete', $prize));
    }

    public function test_order_policy_is_view_only(): void
    {
        $operations = Admin::factory()->create();
        $order = Order::factory()->create();

        $this->assertTrue($operations->can('view', $order));
        $this->assertFalse(method_exists(\App\Policies\OrderPolicy::class, 'update'));
    }

    public function test_payment_policy_is_view_only_for_both_roles(): void
    {
        $operations = Admin::factory()->create();
        $superAdmin = Admin::factory()->superAdmin()->create();
        $payment = Payment::factory()->create();

        $this->assertTrue($operations->can('view', $payment));
        $this->assertTrue($superAdmin->can('view', $payment));
        // No mutating ability exists on the payment policy at all — see
        // PaymentPolicy's docblock: this is a structural guarantee, not
        // just a role check.
        $this->assertFalse(method_exists(\App\Policies\PaymentPolicy::class, 'update'));
        $this->assertFalse(method_exists(\App\Policies\PaymentPolicy::class, 'markPaid'));
    }

    public function test_entry_and_ticket_policies_grant_view_to_operations(): void
    {
        $operations = Admin::factory()->create();
        $entry = Entry::factory()->create();
        $ticket = Ticket::factory()->create();

        $this->assertTrue($operations->can('view', $entry));
        $this->assertTrue($operations->can('view', $ticket));
    }

    public function test_draw_policy_grants_run_to_operations(): void
    {
        $operations = Admin::factory()->create();
        $draw = Draw::factory()->create();

        $this->assertTrue($operations->can('view', $draw));
        $this->assertTrue($operations->can('run', Draw::class));
    }

    public function test_winner_policy_grants_transition_to_operations(): void
    {
        $operations = Admin::factory()->create();
        $winner = Winner::factory()->create();

        $this->assertTrue($operations->can('view', $winner));
        $this->assertTrue($operations->can('transition', $winner));
        // Identity fields are never admin-mutable through this policy.
        $this->assertFalse(method_exists(\App\Policies\WinnerPolicy::class, 'update'));
    }

    public function test_winner_claim_policy_grants_full_workflow_to_operations(): void
    {
        $operations = Admin::factory()->create();
        $claim = WinnerClaim::factory()->create();

        $this->assertTrue($operations->can('view', $claim));
        $this->assertTrue($operations->can('verify', $claim));
        $this->assertTrue($operations->can('approve', $claim));
        $this->assertTrue($operations->can('reject', $claim));
    }

    public function test_prize_delivery_policy_grants_fulfilment_workflow_to_operations(): void
    {
        $operations = Admin::factory()->create();
        $delivery = PrizeDelivery::factory()->create();

        $this->assertTrue($operations->can('view', $delivery));
        $this->assertTrue($operations->can('advance', $delivery));
        $this->assertTrue($operations->can('assignPrize', PrizeDelivery::class));
    }

    public function test_user_policy_grants_customer_view_only(): void
    {
        $operations = Admin::factory()->create();
        $customer = User::factory()->create();

        $this->assertTrue($operations->can('view', $customer));
        $this->assertFalse(method_exists(\App\Policies\UserPolicy::class, 'update'));
    }

    public function test_audit_log_policies_are_super_admin_only(): void
    {
        $operations = Admin::factory()->create();
        $superAdmin = Admin::factory()->superAdmin()->create();
        $drawLog = DrawAuditLog::factory()->create();
        $winnerLog = WinnerAuditLog::factory()->create();

        $this->assertFalse($operations->can('view', $drawLog));
        $this->assertFalse($operations->can('view', $winnerLog));

        $this->assertTrue($superAdmin->can('view', $drawLog));
        $this->assertTrue($superAdmin->can('view', $winnerLog));
    }
}
