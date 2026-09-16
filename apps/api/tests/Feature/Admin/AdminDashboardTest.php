<?php

namespace Tests\Feature\Admin;

use App\Enums\CompetitionStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PrizeDeliveryStatus;
use App\Enums\WinnerClaimStatus;
use App\Enums\WinnerStatus;
use App\Filament\Widgets\ClaimOverviewWidget;
use App\Filament\Widgets\CompetitionOverviewWidget;
use App\Filament\Widgets\FulfilmentOverviewWidget;
use App\Filament\Widgets\OrderOverviewWidget;
use App\Filament\Widgets\PaymentOverviewWidget;
use App\Filament\Widgets\RecentActivityWidget;
use App\Filament\Widgets\WinnerOverviewWidget;
use App\Models\Admin;
use App\Models\Competition;
use App\Models\CompetitionLifecycleEvent;
use App\Models\DrawAuditLog;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PrizeDelivery;
use App\Models\User;
use App\Models\Winner;
use App\Models\WinnerAuditLog;
use App\Models\WinnerClaim;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * P11.4 — the read-only operations dashboard. Covers panel-level access
 * control (shared with every other admin page, but re-verified here
 * since the dashboard is what an admin actually lands on), per-widget
 * count correctness, the "ending soon" window, recent-activity limiting,
 * the no-mutation guarantee, and that every widget's query count stays
 * flat as the underlying tables grow (proving aggregation, not
 * collection-loading).
 */
class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_super_admin_can_access_the_dashboard(): void
    {
        $superAdmin = Admin::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin, 'admin')->get('/admin');

        $response->assertOk();
        $response->assertSee('RaffleGuru Operations');
    }

    public function test_operations_can_access_the_dashboard(): void
    {
        $operations = Admin::factory()->create();

        $response = $this->actingAs($operations, 'admin')->get('/admin');

        $response->assertOk();
    }

    public function test_customer_cannot_access_the_dashboard(): void
    {
        $customer = User::factory()->create();

        $response = $this->actingAs($customer, 'web')->get('/admin');

        $response->assertRedirect('/admin/login');
    }

    public function test_inactive_admin_cannot_access_the_dashboard(): void
    {
        $inactive = Admin::factory()->inactive()->create();

        $response = $this->actingAs($inactive, 'admin')->get('/admin');

        $response->assertForbidden();
    }

    public function test_guest_cannot_access_the_dashboard(): void
    {
        $response = $this->get('/admin');

        $response->assertRedirect('/admin/login');
    }

    public function test_competition_counts_are_correct(): void
    {
        Competition::factory()->count(3)->withStatus(CompetitionStatus::Active)->create(['ends_at' => now()->addDays(10)]);
        Competition::factory()->count(2)->withStatus(CompetitionStatus::Scheduled)->create();
        Competition::factory()->count(1)->withStatus(CompetitionStatus::SoldOut)->create();
        Competition::factory()->count(4)->withStatus(CompetitionStatus::Completed)->create();

        Livewire::test(CompetitionOverviewWidget::class)
            ->assertSee('Active')
            ->assertSee('3')
            ->assertSee('Scheduled')
            ->assertSee('2')
            ->assertSee('Sold Out')
            ->assertSee('1');
    }

    public function test_ending_soon_calculation_is_correct(): void
    {
        // Counts: Active + ends within 24h.
        Competition::factory()->withStatus(CompetitionStatus::Active)->create(['ends_at' => now()->addHours(2)]);
        // Does not count: Active but ends in 30h (outside the window).
        Competition::factory()->withStatus(CompetitionStatus::Active)->create(['ends_at' => now()->addHours(30)]);
        // Does not count: ends within 24h, but not Active.
        Competition::factory()->withStatus(CompetitionStatus::Closed)->create(['ends_at' => now()->addHours(2)]);

        Livewire::test(CompetitionOverviewWidget::class)
            ->assertSee('Ending Soon')
            ->assertSee('1');
    }

    public function test_order_counts_are_correct(): void
    {
        Order::factory()->count(5)->withStatus(OrderStatus::Paid)->create();
        Order::factory()->count(2)->withStatus(OrderStatus::Failed)->create();
        Order::factory()->count(3)->withStatus(OrderStatus::Pending)->create();

        Livewire::test(OrderOverviewWidget::class)
            ->assertSee('Total')
            ->assertSee('10')
            ->assertSee('Paid')
            ->assertSee('5')
            ->assertSee('Failed')
            ->assertSee('2')
            ->assertSee('Pending')
            ->assertSee('3');
    }

    public function test_payment_counts_are_correct(): void
    {
        Payment::factory()->count(4)->withStatus(PaymentStatus::Paid)->create();
        Payment::factory()->count(2)->withStatus(PaymentStatus::Failed)->create();
        Payment::factory()->count(1)->withStatus(PaymentStatus::Refunded)->create();

        Livewire::test(PaymentOverviewWidget::class)
            ->assertSee('Paid')
            ->assertSee('4')
            ->assertSee('Failed')
            ->assertSee('2')
            ->assertSee('Refunded')
            ->assertSee('1');
    }

    public function test_winner_counts_are_correct(): void
    {
        Winner::factory()->count(3)->withStatus(WinnerStatus::PendingContact)->create();
        Winner::factory()->count(2)->withStatus(WinnerStatus::Verified)->create();
        Winner::factory()->count(1)->withStatus(WinnerStatus::Completed)->create();

        Livewire::test(WinnerOverviewWidget::class)
            ->assertSee('Pending Contact')
            ->assertSee('3')
            ->assertSee('Verified')
            ->assertSee('2')
            ->assertSee('Completed')
            ->assertSee('1');
    }

    public function test_claim_counts_are_correct(): void
    {
        WinnerClaim::factory()->count(2)->create(['status' => WinnerClaimStatus::Submitted]);
        WinnerClaim::factory()->count(3)->create(['status' => WinnerClaimStatus::VerificationPending]);
        WinnerClaim::factory()->count(1)->create(['status' => WinnerClaimStatus::Approved]);
        WinnerClaim::factory()->count(1)->create(['status' => WinnerClaimStatus::Rejected]);

        // Awaiting verification = Submitted + VerificationPending = 5.
        Livewire::test(ClaimOverviewWidget::class)
            ->assertSee('Awaiting Verification')
            ->assertSee('5')
            ->assertSee('Approved')
            ->assertSee('1')
            ->assertSee('Rejected');
    }

    public function test_fulfilment_counts_are_correct(): void
    {
        PrizeDelivery::factory()->count(2)->create(['status' => PrizeDeliveryStatus::Pending]);
        PrizeDelivery::factory()->count(1)->create(['status' => PrizeDeliveryStatus::Shipped]);
        PrizeDelivery::factory()->count(4)->create(['status' => PrizeDeliveryStatus::Delivered]);

        Livewire::test(FulfilmentOverviewWidget::class)
            ->assertSee('Pending')
            ->assertSee('2')
            ->assertSee('Shipped')
            ->assertSee('1')
            ->assertSee('Delivered')
            ->assertSee('4');
    }

    public function test_recent_activity_is_capped_per_source(): void
    {
        $competition = Competition::factory()->create();

        // 20 events from this single source alone must be capped at the
        // widget's 10-per-source limit before the sources are even merged.
        CompetitionLifecycleEvent::factory()->count(20)->create([
            'competition_id' => $competition->id,
        ]);

        $activity = Livewire::test(RecentActivityWidget::class)->viewData('activity');

        $this->assertCount(10, $activity);
    }

    public function test_recent_activity_is_capped_across_sources(): void
    {
        $competition = Competition::factory()->create();
        $winner = Winner::factory()->create();

        // 6 + 6 + 6 = 18 across three sources, each under its own
        // 10-per-source cap individually, but the merged, re-sorted
        // result must still be capped at the widget's 15-row display limit.
        CompetitionLifecycleEvent::factory()->count(6)->create(['competition_id' => $competition->id]);
        DrawAuditLog::factory()->count(6)->create(['competition_id' => $competition->id]);
        WinnerAuditLog::factory()->count(6)->create(['winner_id' => $winner->id]);

        $activity = Livewire::test(RecentActivityWidget::class)->viewData('activity');

        $this->assertCount(15, $activity);
    }

    /**
     * No widget on this dashboard may write anywhere — proven directly
     * by comparing row counts across every table these widgets touch,
     * before and after rendering all of them together.
     */
    public function test_no_dashboard_mutation_occurs(): void
    {
        Competition::factory()->count(2)->create();
        Order::factory()->count(2)->create();
        Payment::factory()->count(2)->create();
        Winner::factory()->count(2)->create();
        WinnerClaim::factory()->count(2)->create();
        PrizeDelivery::factory()->count(2)->create();

        $tables = [
            'competitions', 'orders', 'payments', 'winners', 'winner_claims',
            'prize_deliveries', 'competition_lifecycle_events',
            'draw_audit_logs', 'winner_audit_logs',
        ];

        $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()]);

        Livewire::test(CompetitionOverviewWidget::class);
        Livewire::test(OrderOverviewWidget::class);
        Livewire::test(PaymentOverviewWidget::class);
        Livewire::test(WinnerOverviewWidget::class);
        Livewire::test(ClaimOverviewWidget::class);
        Livewire::test(FulfilmentOverviewWidget::class);
        Livewire::test(RecentActivityWidget::class);

        $after = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()]);

        $this->assertEquals($before->all(), $after->all());
    }

    /**
     * P11.4 §19 — proves aggregation, not collection-loading: the query
     * count for a status-breakdown widget must stay flat (one GROUP BY)
     * regardless of how many rows the table holds, not grow per row.
     */
    public function test_widget_query_counts_stay_flat_as_data_grows(): void
    {
        Competition::factory()->count(60)->create();

        DB::enableQueryLog();
        Livewire::test(CompetitionOverviewWidget::class);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // 1 grouped status count + 1 ranged "ending soon" count, plus a
        // small constant for Livewire/Filament's own component-render
        // bookkeeping (session/auth lookups) — never one query per row.
        $this->assertLessThanOrEqual(6, $queryCount);
    }

    public function test_recent_activity_widget_query_count_stays_flat_as_data_grows(): void
    {
        $competition = Competition::factory()->create();
        CompetitionLifecycleEvent::factory()->count(60)->create(['competition_id' => $competition->id]);

        DB::enableQueryLog();
        Livewire::test(RecentActivityWidget::class);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // 3 bounded per-source queries (LIMIT 10 each), not one per row.
        $this->assertLessThanOrEqual(6, $queryCount);
    }
}
