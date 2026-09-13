<?php

namespace Tests\Feature\Order;

use App\Enums\CompetitionStatus;
use App\Models\Competition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SerializationTest extends TestCase
{
    use RefreshDatabase;

    public function test_cart_response_exposes_only_intended_fields(): void
    {
        $user = User::factory()->create();
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create();
        $this->actingAs($user)->postJson('/api/v1/cart/items', ['competition_id' => $competition->id, 'quantity' => 1]);

        $response = $this->actingAs($user)->getJson('/api/v1/cart');

        $cartKeys = array_keys($response->json('data'));
        sort($cartKeys);
        $this->assertSame(['count', 'currency', 'id', 'items', 'subtotal'], $cartKeys);

        $itemKeys = array_keys($response->json('data.items.0'));
        sort($itemKeys);
        $this->assertSame(['competition', 'currency', 'id', 'line_total', 'quantity', 'unit_price'], $itemKeys);
    }

    public function test_order_response_exposes_only_intended_fields_and_no_payment_data(): void
    {
        $user = User::factory()->create();
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create();
        $this->actingAs($user)->postJson('/api/v1/cart/items', ['competition_id' => $competition->id, 'quantity' => 1]);

        $response = $this->actingAs($user)->postJson('/api/v1/orders');

        $orderKeys = array_keys($response->json('data'));
        sort($orderKeys);
        $this->assertSame(
            ['created_at', 'currency', 'id', 'items', 'reference', 'status', 'subtotal', 'total'],
            $orderKeys,
        );

        $body = $response->getContent();
        foreach (['card', 'payment_method', 'password', 'ticket_number', 'cvv'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body);
        }
    }

    public function test_money_fields_are_integers_never_floats(): void
    {
        $user = User::factory()->create();
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create(['entry_price' => 99]);
        $this->actingAs($user)->postJson('/api/v1/cart/items', ['competition_id' => $competition->id, 'quantity' => 3]);

        $response = $this->actingAs($user)->postJson('/api/v1/orders');

        $this->assertIsInt($response->json('data.subtotal'));
        $this->assertIsInt($response->json('data.total'));
        $this->assertIsInt($response->json('data.items.0.unit_price'));
        $this->assertIsInt($response->json('data.items.0.line_total'));
    }
}
