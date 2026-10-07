<?php

namespace Tests\Feature\Ticket;

use App\Enums\CompetitionStatus;
use App\Enums\OrderStatus;
use App\Models\Competition;
use App\Models\Entry;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tickets\TicketAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntryApiTest extends TestCase
{
    use RefreshDatabase;

    private function allocatedEntryFor(User $user): Entry
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create(['max_entries' => 1000]);
        $order = Order::factory()->for($user)->withStatus(OrderStatus::Paid)->create();
        OrderItem::factory()->for($order)->for($competition)->create(['quantity' => 3]);

        app(TicketAllocationService::class)->allocateOrder($order);

        return Entry::query()->where('order_id', $order->id)->firstOrFail();
    }

    public function test_unauthenticated_entry_listing_is_rejected(): void
    {
        $this->getJson('/api/v1/entries')->assertUnauthorized();
    }

    public function test_unauthenticated_entry_detail_is_rejected(): void
    {
        $entry = $this->allocatedEntryFor(User::factory()->create());

        $this->getJson("/api/v1/entries/{$entry->id}")->assertUnauthorized();
    }

    public function test_entry_listing_returns_only_the_authenticated_users_entries(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $entry = $this->allocatedEntryFor($user);
        $this->allocatedEntryFor($otherUser);

        $response = $this->actingAs($user)->getJson('/api/v1/entries');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($entry->id, $response->json('data.0.id'));
    }

    public function test_entry_detail_returns_the_owners_entry_with_ticket_numbers(): void
    {
        $user = User::factory()->create();
        $entry = $this->allocatedEntryFor($user);

        $response = $this->actingAs($user)->getJson("/api/v1/entries/{$entry->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $entry->id)
            ->assertJsonPath('data.quantity', 3)
            ->assertJsonCount(3, 'data.ticket_numbers');
        $this->assertSame([1, 2, 3], $response->json('data.ticket_numbers'));
    }

    public function test_cross_user_entry_access_is_rejected(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $entry = $this->allocatedEntryFor($owner);

        $this->actingAs($intruder)->getJson("/api/v1/entries/{$entry->id}")->assertNotFound();
    }

    public function test_unknown_entry_id_returns_404(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/v1/entries/999999')->assertNotFound();
    }

    public function test_entry_resource_never_exposes_another_users_data_or_internal_ticket_ids(): void
    {
        // ticket_number is scoped per competition and always starts at 1
        // for a fresh one (see allocatedEntryFor), so the entry under test
        // below will always have ticket_numbers {1, 2, 3} regardless of
        // which database engine runs this test. tickets.id is a single
        // global autoincrement, so it is NOT scoped per competition — this
        // decoy allocation claims the first three global ticket ids before
        // the real entry is created, guaranteeing the real entry's internal
        // ticket ids land outside {1, 2, 3} on every engine. Without this,
        // a fresh SQLite :memory: database (where the real entry's tickets
        // would otherwise also get global ids 1-3) makes the assertion
        // below pass by numeric coincidence rather than by actually
        // distinguishing "internal id" from "public ticket number".
        $this->allocatedEntryFor(User::factory()->create());

        $user = User::factory()->create();
        $entry = $this->allocatedEntryFor($user);
        $ticketId = Ticket::query()->where('entry_id', $entry->id)->first()->id;

        $this->assertNotContains($ticketId, [1, 2, 3], 'Fixture setup must keep the internal ticket id outside the public ticket-number range — otherwise this test cannot detect a real leak.');

        $response = $this->actingAs($user)->getJson("/api/v1/entries/{$entry->id}");

        $entryKeys = array_keys($response->json('data'));
        sort($entryKeys);
        $this->assertSame(['allocated_at', 'competition', 'id', 'order_reference', 'quantity', 'ticket_numbers'], $entryKeys);

        // The internal tickets.id must never appear as a "ticket number".
        $this->assertNotContains($ticketId, $response->json('data.ticket_numbers'));
    }

    public function test_no_ticket_mutation_route_exists(): void
    {
        $user = User::factory()->create();
        $entry = $this->allocatedEntryFor($user);

        $this->actingAs($user)->patchJson("/api/v1/entries/{$entry->id}", ['quantity' => 99])->assertStatus(405);
        $this->actingAs($user)->deleteJson("/api/v1/entries/{$entry->id}")->assertStatus(405);
    }
}
