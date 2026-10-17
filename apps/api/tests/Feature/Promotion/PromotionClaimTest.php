<?php

namespace Tests\Feature\Promotion;

use App\Enums\CompetitionStatus;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PromotionClaimStatus;
use App\Models\Competition;
use App\Models\Entry;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Promotion;
use App\Models\PromotionClaim;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class PromotionClaimTest extends TestCase
{
    use RefreshDatabase;

    private function openPromotion(array $overrides = []): Promotion
    {
        return Promotion::factory()->create(array_merge([
            'competition_id' => Competition::factory()->withStatus(CompetitionStatus::Active)->create(['max_entries' => 1000]),
            'is_enabled' => true,
            'starts_at' => null,
            'ends_at' => null,
            'total_capacity' => 10,
        ], $overrides));
    }

    // ---- Happy path ---------------------------------------------------

    public function test_a_verified_authenticated_user_can_claim_an_open_promotion(): void
    {
        $promotion = $this->openPromotion();
        $user = User::factory()->create(['email_verified_at' => now()]);

        $response = $this->actingAs($user)->postJson("/api/v1/account/promotions/{$promotion->slug}/claim");

        $response->assertOk()->assertJsonPath('data.status', 'fulfilled');

        $this->assertDatabaseHas('promotion_claims', [
            'promotion_id' => $promotion->id,
            'user_id' => $user->id,
            'status' => PromotionClaimStatus::Fulfilled->value,
        ]);

        $order = Order::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(OrderSource::Promotional, $order->source);
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertSame(0, $order->total);
        $this->assertSame(0, Payment::where('order_id', $order->id)->count());

        $this->assertSame(1, Ticket::where('user_id', $user->id)->where('competition_id', $promotion->competition_id)->count());
        $this->assertSame(1, Entry::where('user_id', $user->id)->count());
    }

    public function test_claiming_also_subscribes_the_users_email_to_the_newsletter(): void
    {
        $promotion = $this->openPromotion();
        $user = User::factory()->create(['email' => 'claimer@example.com', 'email_verified_at' => now()]);

        $this->actingAs($user)->postJson("/api/v1/account/promotions/{$promotion->slug}/claim")->assertOk();

        $this->assertDatabaseHas('newsletter_subscribers', [
            'email' => 'claimer@example.com',
            'unsubscribed_at' => null,
        ]);
    }

    public function test_response_never_exposes_internal_ids_beyond_the_callers_own_entry(): void
    {
        $promotion = $this->openPromotion();
        $user = User::factory()->create(['email_verified_at' => now()]);

        $response = $this->actingAs($user)->postJson("/api/v1/account/promotions/{$promotion->slug}/claim");

        $response->assertJsonMissingPath('data.user_id');
        $response->assertJsonMissingPath('data.promotion_id');
        $response->assertJsonMissingPath('data.order_id');
        $response->assertJsonMissingPath('data.newsletter_subscriber_id');
    }

    // ---- Authentication / verification ---------------------------------

    public function test_a_guest_cannot_claim(): void
    {
        $promotion = $this->openPromotion();

        $this->postJson("/api/v1/account/promotions/{$promotion->slug}/claim")->assertUnauthorized();

        $this->assertDatabaseCount('promotion_claims', 0);
    }

    public function test_an_unverified_user_cannot_claim(): void
    {
        $promotion = $this->openPromotion();
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->postJson("/api/v1/account/promotions/{$promotion->slug}/claim")->assertForbidden();

        $this->assertDatabaseCount('promotion_claims', 0);
    }

    // ---- Ineligible promotion states -----------------------------------

    public function test_a_disabled_promotion_cannot_be_claimed(): void
    {
        $promotion = $this->openPromotion(['is_enabled' => false]);
        $user = User::factory()->create(['email_verified_at' => now()]);

        $response = $this->actingAs($user)->postJson("/api/v1/account/promotions/{$promotion->slug}/claim");

        $response->assertUnprocessable()->assertJsonValidationErrors('promotion');
        $this->assertDatabaseHas('promotion_claims', ['user_id' => $user->id, 'status' => PromotionClaimStatus::Failed->value]);
        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_a_not_yet_started_promotion_cannot_be_claimed(): void
    {
        $promotion = $this->openPromotion(['starts_at' => now()->addDay()]);
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)->postJson("/api/v1/account/promotions/{$promotion->slug}/claim")
            ->assertUnprocessable()->assertJsonValidationErrors('promotion');
    }

    public function test_an_ended_promotion_cannot_be_claimed(): void
    {
        $promotion = $this->openPromotion(['starts_at' => now()->subWeek(), 'ends_at' => now()->subDay()]);
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)->postJson("/api/v1/account/promotions/{$promotion->slug}/claim")
            ->assertUnprocessable()->assertJsonValidationErrors('promotion');
    }

    public function test_an_exhausted_promotion_cannot_be_claimed(): void
    {
        $promotion = $this->openPromotion(['total_capacity' => 1]);
        $first = User::factory()->create(['email_verified_at' => now()]);
        $second = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($first)->postJson("/api/v1/account/promotions/{$promotion->slug}/claim")->assertOk();
        $response = $this->actingAs($second)->postJson("/api/v1/account/promotions/{$promotion->slug}/claim");

        $response->assertUnprocessable()->assertJsonValidationErrors('promotion');
        $this->assertSame(1, Ticket::where('competition_id', $promotion->competition_id)->count());
        $this->assertDatabaseCount('promotion_claims', 2);
    }

    public function test_a_claim_fails_cleanly_when_the_competition_is_no_longer_purchasable(): void
    {
        $promotion = $this->openPromotion([
            'competition_id' => Competition::factory()->withStatus(CompetitionStatus::Closed)->create(),
        ]);
        $user = User::factory()->create(['email_verified_at' => now()]);

        $response = $this->actingAs($user)->postJson("/api/v1/account/promotions/{$promotion->slug}/claim");

        $response->assertUnprocessable()->assertJsonValidationErrors('promotion');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('entries', 0);
    }

    public function test_a_claim_fails_cleanly_when_the_competitions_real_capacity_is_full_leaving_no_orphans(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create(['max_entries' => 0]);
        $promotion = $this->openPromotion(['competition_id' => $competition]);
        $user = User::factory()->create(['email_verified_at' => now()]);

        $response = $this->actingAs($user)->postJson("/api/v1/account/promotions/{$promotion->slug}/claim");

        $response->assertUnprocessable()->assertJsonValidationErrors('promotion');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('entries', 0);
        $this->assertDatabaseHas('promotion_claims', [
            'user_id' => $user->id,
            'status' => PromotionClaimStatus::Failed->value,
            'failure_reason' => 'The competition for this offer is full.',
        ]);
    }

    public function test_an_unknown_promotion_slug_is_a_plain_404(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)->postJson('/api/v1/account/promotions/does-not-exist/claim')->assertNotFound();
    }

    // ---- Idempotency / duplicates ---------------------------------------

    public function test_repeated_claims_by_the_same_user_never_award_a_second_ticket(): void
    {
        $promotion = $this->openPromotion();
        $user = User::factory()->create(['email_verified_at' => now()]);

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($user)->postJson("/api/v1/account/promotions/{$promotion->slug}/claim")->assertOk();
        }

        $this->assertDatabaseCount('promotion_claims', 1);
        $this->assertSame(1, Ticket::where('user_id', $user->id)->count());
    }

    public function test_two_different_emails_that_normalize_to_different_users_each_get_their_own_claim(): void
    {
        $promotion = $this->openPromotion();
        $a = User::factory()->create(['email_verified_at' => now()]);
        $b = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($a)->postJson("/api/v1/account/promotions/{$promotion->slug}/claim")->assertOk();
        $this->actingAs($b)->postJson("/api/v1/account/promotions/{$promotion->slug}/claim")->assertOk();

        $this->assertDatabaseCount('promotion_claims', 2);
        $this->assertSame(2, Ticket::where('competition_id', $promotion->competition_id)->count());
    }

    public function test_a_retry_after_a_transient_failure_can_still_succeed_once_eligible(): void
    {
        $promotion = $this->openPromotion(['is_enabled' => false]);
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)->postJson("/api/v1/account/promotions/{$promotion->slug}/claim")
            ->assertUnprocessable();
        $this->assertDatabaseCount('promotion_claims', 1);

        $promotion->update(['is_enabled' => true]);

        $this->actingAs($user)->postJson("/api/v1/account/promotions/{$promotion->slug}/claim")
            ->assertOk()->assertJsonPath('data.status', 'fulfilled');

        // Still exactly one row — the failed attempt's row was reused,
        // never a second one inserted.
        $this->assertDatabaseCount('promotion_claims', 1);
        $this->assertSame(1, Ticket::where('user_id', $user->id)->count());
    }

    public function test_a_fulfilled_claim_is_immutable_at_the_model_layer(): void
    {
        $promotion = $this->openPromotion();
        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->actingAs($user)->postJson("/api/v1/account/promotions/{$promotion->slug}/claim")->assertOk();

        $claim = PromotionClaim::where('user_id', $user->id)->firstOrFail();

        $this->expectException(LogicException::class);
        $claim->update(['status' => PromotionClaimStatus::Failed]);
    }

    // ---- Read endpoints --------------------------------------------------

    public function test_claim_status_endpoint_reports_none_then_fulfilled(): void
    {
        $promotion = $this->openPromotion();
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)->getJson("/api/v1/account/promotions/{$promotion->slug}/claim")
            ->assertOk()->assertJson(['data' => null]);

        $this->actingAs($user)->postJson("/api/v1/account/promotions/{$promotion->slug}/claim")->assertOk();

        $this->actingAs($user)->getJson("/api/v1/account/promotions/{$promotion->slug}/claim")
            ->assertOk()->assertJsonPath('data.status', 'fulfilled');
    }

    public function test_public_promotion_endpoint_reports_open_state_without_requiring_auth(): void
    {
        $promotion = $this->openPromotion();

        $this->getJson("/api/v1/promotions/{$promotion->slug}")
            ->assertOk()
            ->assertJsonPath('data.is_open', true)
            ->assertJsonMissingPath('data.total_capacity');
    }

    public function test_public_promotion_endpoint_is_honest_about_a_disabled_promotion(): void
    {
        $promotion = $this->openPromotion(['is_enabled' => false]);

        $this->getJson("/api/v1/promotions/{$promotion->slug}")
            ->assertOk()
            ->assertJsonPath('data.is_open', false);
    }

    public function test_public_promotion_endpoint_returns_null_data_for_an_unknown_slug(): void
    {
        $this->getJson('/api/v1/promotions/does-not-exist')
            ->assertOk()
            ->assertJson(['data' => null]);
    }

    public function test_claim_is_rate_limited(): void
    {
        $promotion = $this->openPromotion();
        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->actingAs($user);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson("/api/v1/account/promotions/{$promotion->slug}/claim");
        }

        $this->postJson("/api/v1/account/promotions/{$promotion->slug}/claim")->assertTooManyRequests();
    }
}
