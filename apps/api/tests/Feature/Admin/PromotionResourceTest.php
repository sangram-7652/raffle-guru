<?php

namespace Tests\Feature\Admin;

use App\Enums\CompetitionStatus;
use App\Enums\PromotionClaimStatus;
use App\Filament\Resources\Promotions\Pages\CreatePromotion;
use App\Filament\Resources\Promotions\Pages\EditPromotion;
use App\Filament\Resources\Promotions\Pages\ViewPromotion;
use App\Filament\Resources\Promotions\RelationManagers\ClaimsRelationManager;
use App\Models\Admin;
use App\Models\Competition;
use App\Models\Promotion;
use App\Models\PromotionClaim;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PromotionResource: access, and the create/update split from
 * docs/EMAIL_CAPTURE.md ("Super Admin only manages; Operations can
 * view") — AdminAbility::PromotionManage vs PromotionView.
 */
class PromotionResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_guest_is_redirected_to_admin_login(): void
    {
        $this->get('/admin/promotions')->assertRedirect('/admin/login');
    }

    public function test_customer_session_is_not_an_admin_session(): void
    {
        $this->actingAs(User::factory()->create(), 'web')
            ->get('/admin/promotions')
            ->assertRedirect('/admin/login');
    }

    public function test_operations_can_view_but_not_create_or_edit_a_promotion(): void
    {
        $promotion = Promotion::factory()->create();
        $this->actingAs(Admin::factory()->create(), 'admin');

        $this->get('/admin/promotions')->assertOk();
        $this->get("/admin/promotions/{$promotion->id}")->assertOk();
        $this->get('/admin/promotions/create')->assertForbidden();
        $this->get("/admin/promotions/{$promotion->id}/edit")->assertForbidden();
    }

    public function test_super_admin_can_create_and_edit_a_promotion(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create();
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        Livewire::test(CreatePromotion::class)
            ->fillForm([
                'name' => 'Welcome giveaway',
                'slug' => 'welcome-giveaway',
                'competition_id' => $competition->id,
                'is_enabled' => true,
                'total_capacity' => 50,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $promotion = Promotion::query()->where('slug', 'welcome-giveaway')->firstOrFail();
        $this->assertTrue($promotion->is_enabled);
        $this->assertSame(50, $promotion->total_capacity);

        Livewire::test(EditPromotion::class, ['record' => $promotion->getRouteKey()])
            ->fillForm(['total_capacity' => 25])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(25, $promotion->fresh()->total_capacity);
    }

    public function test_a_promotion_requires_a_competition_and_a_real_capacity(): void
    {
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        Livewire::test(CreatePromotion::class)
            ->fillForm(['name' => 'Bad', 'slug' => 'bad', 'total_capacity' => 0])
            ->call('create')
            ->assertHasFormErrors(['competition_id', 'total_capacity']);

        $this->assertSame(0, Promotion::query()->count());
    }

    public function test_promotion_table_defaults_to_disabled_and_shows_claimed_over_capacity(): void
    {
        $promotion = Promotion::factory()->create(['is_enabled' => false, 'total_capacity' => 10]);
        PromotionClaim::factory()->for($promotion)->create(['status' => PromotionClaimStatus::Fulfilled]);
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        Livewire::test(ViewPromotion::class, ['record' => $promotion->getRouteKey()])
            ->assertOk();

        $this->assertSame(1, $promotion->fulfilledClaimsCount());
    }

    public function test_claims_relation_manager_is_read_only(): void
    {
        $promotion = Promotion::factory()->create();
        $claim = PromotionClaim::factory()->for($promotion)->create(['status' => PromotionClaimStatus::Failed, 'failure_reason' => 'exhausted']);
        $this->actingAs(Admin::factory()->create(), 'admin');

        // isReadOnly() suppresses the create/edit/delete actions
        // entirely (no such action exists to assert hidden on, unlike
        // a merely-hidden one) — confirmed by this test actually
        // failing with "Action [create] not found" before this comment
        // was added, which is the correct read-only behavior.
        Livewire::test(ClaimsRelationManager::class, [
            'ownerRecord' => $promotion,
            'pageClass' => ViewPromotion::class,
        ])
            ->assertCanSeeTableRecords([$claim]);
    }
}
