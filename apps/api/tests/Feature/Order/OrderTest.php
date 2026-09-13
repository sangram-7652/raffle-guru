<?php

namespace Tests\Feature\Order;

use App\Enums\CompetitionStatus;
use App\Enums\OrderStatus;
use App\Models\Competition;
use App\Models\Order;
use App\Models\Prize;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_order_creation_is_rejected(): void
    {
        $this->postJson('/api/v1/orders')->assertUnauthorized();
    }

    public function test_unauthenticated_order_listing_is_rejected(): void
    {
        $this->getJson('/api/v1/orders')->assertUnauthorized();
    }

    public function test_empty_cart_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/orders')->assertUnprocessable();
    }

    public function test_order_created_from_cart_with_correct_totals_and_pending_status(): void
    {
        $user = User::factory()->create();
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create(['entry_price' => 150]);
        Prize::factory()->for($competition)->create();
        $this->actingAs($user)->postJson('/api/v1/cart/items', ['competition_id' => $competition->id, 'quantity' => 4]);

        $response = $this->actingAs($user)->postJson('/api/v1/orders');

        $response->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.subtotal', 600)
            ->assertJsonPath('data.total', 600)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.quantity', 4)
            ->assertJsonPath('data.items.0.unit_price', 150)
            ->assertJsonPath('data.items.0.line_total', 600);

        $this->assertDatabaseHas('orders', ['user_id' => $user->id, 'status' => OrderStatus::Pending->value]);
    }

    public function test_order_reference_is_generated_and_unique_looking(): void
    {
        $user = User::factory()->create();
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create();
        $this->actingAs($user)->postJson('/api/v1/cart/items', ['competition_id' => $competition->id, 'quantity' => 1]);

        $response = $this->actingAs($user)->postJson('/api/v1/orders');

        $reference = $response->json('data.reference');
        $this->assertMatchesRegularExpression('/^RG-\d{6}$/', $reference);
    }

    public function test_cart_is_cleared_after_order_creation(): void
    {
        $user = User::factory()->create();
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create();
        $this->actingAs($user)->postJson('/api/v1/cart/items', ['competition_id' => $competition->id, 'quantity' => 1]);

        $this->actingAs($user)->postJson('/api/v1/orders')->assertCreated();

        $this->actingAs($user)->getJson('/api/v1/cart')
            ->assertJsonPath('data.items', [])
            ->assertJsonPath('data.subtotal', 0);
    }

    public function test_order_never_marks_itself_paid(): void
    {
        $user = User::factory()->create();
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create();
        $this->actingAs($user)->postJson('/api/v1/cart/items', ['competition_id' => $competition->id, 'quantity' => 1]);

        $response = $this->actingAs($user)->postJson('/api/v1/orders');

        $this->assertNotSame('paid', $response->json('data.status'));
        $this->assertSame('pending', $response->json('data.status'));
    }

    public function test_order_creation_is_rejected_when_a_cart_item_is_no_longer_purchasable(): void
    {
        $user = User::factory()->create();
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create();
        $this->actingAs($user)->postJson('/api/v1/cart/items', ['competition_id' => $competition->id, 'quantity' => 1]);

        // Goes sold-out after being added to the cart, before checkout.
        $competition->update(['status' => CompetitionStatus::SoldOut]);

        $response = $this->actingAs($user)->postJson('/api/v1/orders');

        $response->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_order_creation_does_not_partially_write_on_failure(): void
    {
        $user = User::factory()->create();
        $competition1 = Competition::factory()->withStatus(CompetitionStatus::Active)->create();
        $competition2 = Competition::factory()->withStatus(CompetitionStatus::Active)->create();
        $this->actingAs($user)->postJson('/api/v1/cart/items', ['competition_id' => $competition1->id, 'quantity' => 1]);
        $this->actingAs($user)->postJson('/api/v1/cart/items', ['competition_id' => $competition2->id, 'quantity' => 1]);
        $competition2->update(['status' => CompetitionStatus::Closed]);

        $this->actingAs($user)->postJson('/api/v1/orders')->assertUnprocessable();

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        // Cart is untouched — nothing was partially consumed.
        $this->assertDatabaseCount('cart_items', 2);
    }

    public function test_order_listing_is_scoped_to_the_authenticated_user_and_paginated(): void
    {
        $user = User::factory()->create();
        Order::factory()->for($user)->count(3)->create();
        $otherUser = User::factory()->create();
        Order::factory()->for($otherUser)->count(2)->create();

        $response = $this->actingAs($user)->getJson('/api/v1/orders');

        $response->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_order_listing_is_newest_first(): void
    {
        $user = User::factory()->create();
        $older = Order::factory()->for($user)->create(['created_at' => now()->subDay()]);
        $newer = Order::factory()->for($user)->create(['created_at' => now()]);

        $response = $this->actingAs($user)->getJson('/api/v1/orders');

        $response->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $older->id);
    }

    public function test_order_detail_returns_only_the_owners_order(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->create();

        $response = $this->actingAs($user)->getJson("/api/v1/orders/{$order->reference}");

        $response->assertOk()->assertJsonPath('data.reference', $order->reference);
    }

    public function test_unknown_order_reference_returns_404(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/v1/orders/RG-000000')->assertNotFound();
    }

    public function test_cross_user_order_access_is_rejected(): void
    {
        $owner = User::factory()->create();
        $order = Order::factory()->for($owner)->create();
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->getJson("/api/v1/orders/{$order->reference}")->assertNotFound();
    }
}
