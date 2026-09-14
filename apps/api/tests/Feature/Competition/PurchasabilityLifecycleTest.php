<?php

namespace Tests\Feature\Competition;

use App\Enums\CompetitionStatus;
use App\Models\Competition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Confirms P8 changes nothing in the purchase path itself (P5) — a
 * competition moved to sold_out/closed/draw_pending by
 * CompetitionLifecycleService is rejected exactly the same way a
 * competition manually set to those statuses already was in P5/P7.
 * See docs/COMPETITION_LIFECYCLE_P8.md "ends_at / closing".
 */
class PurchasabilityLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public static function nonPurchasableStatusProvider(): array
    {
        return [
            'draft' => [CompetitionStatus::Draft],
            'scheduled' => [CompetitionStatus::Scheduled],
            'sold_out' => [CompetitionStatus::SoldOut],
            'closed' => [CompetitionStatus::Closed],
            'draw_pending' => [CompetitionStatus::DrawPending],
            'draw_in_progress' => [CompetitionStatus::DrawInProgress],
            'drawn' => [CompetitionStatus::Drawn],
            'completed' => [CompetitionStatus::Completed],
        ];
    }

    #[DataProvider('nonPurchasableStatusProvider')]
    public function test_only_active_competitions_can_be_added_to_cart(CompetitionStatus $status): void
    {
        $user = User::factory()->create();
        $competition = Competition::factory()->withStatus($status)->create();

        $response = $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'competition_id' => $competition->id,
            'quantity' => 1,
        ]);

        $response->assertUnprocessable();
        $this->assertDatabaseMissing('cart_items', ['competition_id' => $competition->id]);
    }

    public function test_sold_out_competition_set_by_the_lifecycle_service_cannot_be_purchased(): void
    {
        $user = User::factory()->create();
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create(['max_entries' => 1]);

        app(\App\Services\Competitions\CompetitionLifecycleService::class)
            ->transition($competition, CompetitionStatus::SoldOut, 'capacity reached');

        $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'competition_id' => $competition->id,
            'quantity' => 1,
        ])->assertUnprocessable();
    }

    public function test_a_competition_closed_after_being_added_to_cart_is_rejected_at_order_creation(): void
    {
        $user = User::factory()->create();
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create();
        $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'competition_id' => $competition->id,
            'quantity' => 1,
        ])->assertOk();

        app(\App\Services\Competitions\CompetitionLifecycleService::class)
            ->transition($competition, CompetitionStatus::Closed, 'ends_at reached');

        $response = $this->actingAs($user)->postJson('/api/v1/orders');

        $response->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_draw_pending_competition_cannot_be_purchased(): void
    {
        $user = User::factory()->create();
        $competition = Competition::factory()->withStatus(CompetitionStatus::DrawPending)->create();

        $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'competition_id' => $competition->id,
            'quantity' => 1,
        ])->assertUnprocessable();
    }

    // -- Public API --------------------------------------------------

    #[DataProvider('nonPurchasableStatusProvider')]
    public function test_public_api_exposes_the_authoritative_lifecycle_status(CompetitionStatus $status): void
    {
        $competition = Competition::factory()->withStatus($status)->create();

        $response = $this->getJson("/api/v1/competitions?status={$status->value}");

        if (in_array($status, [CompetitionStatus::Draft, CompetitionStatus::Scheduled], true)) {
            // Preserves P4's public-visibility contract exactly.
            $response->assertJsonCount(0, 'data');

            return;
        }

        $response->assertJsonCount(1, 'data');
        $this->assertSame($status->value, $response->json('data.0.status'));
    }

    public function test_scheduled_competition_visibility_is_unchanged_by_p8(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Scheduled)->create();

        $this->getJson('/api/v1/competitions')->assertJsonMissing(['slug' => $competition->slug]);
        $this->getJson("/api/v1/competitions/{$competition->slug}")->assertNotFound();
    }

    public function test_sold_out_competition_remains_publicly_visible(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::SoldOut)->create();

        $this->getJson("/api/v1/competitions/{$competition->slug}")
            ->assertOk()
            ->assertJsonPath('data.status', 'sold_out');
    }

    public function test_lifecycle_internals_never_leak_through_the_public_api(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create();
        app(\App\Services\Competitions\CompetitionLifecycleService::class)
            ->transition($competition, CompetitionStatus::SoldOut, 'capacity reached');

        $response = $this->getJson("/api/v1/competitions/{$competition->slug}");

        $keys = array_keys($response->json('data'));
        $this->assertNotContains('lifecycle_events', $keys);
        $body = $response->getContent();
        $this->assertStringNotContainsString('capacity reached', $body);
        $this->assertStringNotContainsString('reason', $body);
    }
}
