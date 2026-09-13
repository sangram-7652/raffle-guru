<?php

namespace Tests\Feature\Cart;

use App\Enums\CompetitionStatus;
use App\Models\CartItem;
use App\Models\Competition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_access_is_rejected(): void
    {
        $this->getJson('/api/v1/cart')->assertUnauthorized();
    }

    public function test_authenticated_user_gets_an_empty_cart_on_first_access(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/v1/cart');

        $response->assertOk()->assertJson([
            'data' => ['items' => [], 'subtotal' => 0, 'count' => 0],
        ]);
    }

    public function test_add_item_creates_a_cart_line_with_server_resolved_price(): void
    {
        $user = User::factory()->create();
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create(['entry_price' => 149]);

        $response = $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'competition_id' => $competition->id,
            'quantity' => 3,
            // A client-supplied price must be ignored entirely.
            'unit_price' => 1,
        ]);

        $response->assertOk()->assertJsonPath('data.items.0.unit_price', 149)
            ->assertJsonPath('data.items.0.quantity', 3)
            ->assertJsonPath('data.subtotal', 447);
    }

    public function test_adding_the_same_competition_again_increments_quantity_not_a_new_line(): void
    {
        $user = User::factory()->create();
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create(['entry_price' => 100]);

        $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'competition_id' => $competition->id,
            'quantity' => 2,
        ]);
        $response = $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'competition_id' => $competition->id,
            'quantity' => 3,
        ]);

        $response->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.quantity', 5);
        $this->assertSame(1, CartItem::count());
    }

    public function test_update_quantity(): void
    {
        $user = User::factory()->create();
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create(['entry_price' => 100]);
        $this->actingAs($user)->postJson('/api/v1/cart/items', ['competition_id' => $competition->id, 'quantity' => 2]);
        $item = CartItem::firstOrFail();

        $response = $this->actingAs($user)->patchJson("/api/v1/cart/items/{$item->id}", ['quantity' => 7]);

        $response->assertOk()->assertJsonPath('data.items.0.quantity', 7);
    }

    public function test_remove_item(): void
    {
        $user = User::factory()->create();
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create();
        $this->actingAs($user)->postJson('/api/v1/cart/items', ['competition_id' => $competition->id, 'quantity' => 1]);
        $item = CartItem::firstOrFail();

        $response = $this->actingAs($user)->deleteJson("/api/v1/cart/items/{$item->id}");

        $response->assertOk()->assertJsonCount(0, 'data.items');
        $this->assertDatabaseMissing('cart_items', ['id' => $item->id]);
    }

    public function test_zero_quantity_is_rejected(): void
    {
        $user = User::factory()->create();
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create();

        $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'competition_id' => $competition->id,
            'quantity' => 0,
        ])->assertUnprocessable();
    }

    public function test_negative_quantity_is_rejected(): void
    {
        $user = User::factory()->create();
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create();

        $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'competition_id' => $competition->id,
            'quantity' => -5,
        ])->assertUnprocessable();
    }

    public function test_excessive_quantity_is_rejected(): void
    {
        $user = User::factory()->create();
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create();

        $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'competition_id' => $competition->id,
            'quantity' => CartItem::MAX_QUANTITY + 1,
        ])->assertUnprocessable();
    }

    public function test_per_user_entry_limit_is_enforced(): void
    {
        $user = User::factory()->create();
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create(['max_entries_per_user' => 5]);

        $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'competition_id' => $competition->id,
            'quantity' => 6,
        ])->assertUnprocessable();
    }

    public function test_unknown_competition_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'competition_id' => 999999,
            'quantity' => 1,
        ])->assertUnprocessable();
    }

    public function test_draft_competition_cannot_be_added(): void
    {
        $user = User::factory()->create();
        $competition = Competition::factory()->withStatus(CompetitionStatus::Draft)->create();

        $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'competition_id' => $competition->id,
            'quantity' => 1,
        ])->assertUnprocessable();
    }

    public function test_scheduled_competition_cannot_be_added(): void
    {
        $user = User::factory()->create();
        $competition = Competition::factory()->withStatus(CompetitionStatus::Scheduled)->create();

        $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'competition_id' => $competition->id,
            'quantity' => 1,
        ])->assertUnprocessable();
    }

    public function test_sold_out_competition_cannot_be_added(): void
    {
        $user = User::factory()->create();
        $competition = Competition::factory()->withStatus(CompetitionStatus::SoldOut)->create();

        $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'competition_id' => $competition->id,
            'quantity' => 1,
        ])->assertUnprocessable();
    }

    public function test_closed_competition_cannot_be_added(): void
    {
        $user = User::factory()->create();
        $competition = Competition::factory()->withStatus(CompetitionStatus::Closed)->create();

        $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'competition_id' => $competition->id,
            'quantity' => 1,
        ])->assertUnprocessable();
    }

    public function test_soft_deleted_competition_cannot_be_added(): void
    {
        $user = User::factory()->create();
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create();
        $competition->delete();

        $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'competition_id' => $competition->id,
            'quantity' => 1,
        ])->assertUnprocessable();
    }

    public function test_user_a_cannot_modify_user_bs_cart_item(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create();
        $this->actingAs($userA)->postJson('/api/v1/cart/items', ['competition_id' => $competition->id, 'quantity' => 1]);
        $item = CartItem::firstOrFail();

        $this->actingAs($userB)->patchJson("/api/v1/cart/items/{$item->id}", ['quantity' => 9])
            ->assertNotFound();
        $this->actingAs($userB)->deleteJson("/api/v1/cart/items/{$item->id}")
            ->assertNotFound();

        $this->assertSame(1, $item->fresh()->quantity);
    }
}
