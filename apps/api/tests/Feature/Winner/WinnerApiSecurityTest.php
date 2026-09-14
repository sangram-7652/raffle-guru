<?php

namespace Tests\Feature\Winner;

use App\Enums\WinnerClaimStatus;
use App\Enums\WinnerStatus;
use App\Models\User;
use App\Models\Winner;
use App\Services\Winner\WinnerClaimService;
use App\Services\Winner\WinnerLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Winner\Concerns\CreatesWinnerFixtures;
use Tests\TestCase;

/**
 * The full security matrix from docs/WINNER_CLAIM_P10.md "Security
 * tests" — every scenario fails safely (401/404/422), never leaks
 * another user's data, and never lets a customer-supplied field decide
 * ownership or status.
 */
class WinnerApiSecurityTest extends TestCase
{
    use CreatesWinnerFixtures, RefreshDatabase;

    private function pendingContactWinnerFor(?User $user = null): Winner
    {
        ['competition' => $competition, 'winningUser' => $winningUser, 'winningTicket' => $ticket, 'draw' => $draw, 'drawResult' => $drawResult] = $this->createDrawnCompetition();
        $user ??= $winningUser;

        // Re-point the fixture's ticket/order to the desired user when
        // the caller supplied one explicitly is unnecessary here — the
        // fixture's own winningUser is always who actually "won" per
        // ticket.user_id, so the winner is always created for that user.
        return Winner::factory()->create([
            'competition_id' => $competition->id,
            'draw_id' => $draw->id,
            'draw_result_id' => $drawResult->id,
            'ticket_id' => $ticket->id,
            'user_id' => $winningUser->id,
            'status' => WinnerStatus::PendingContact,
        ]);
    }

    private function contactedWinner(): Winner
    {
        $winner = $this->pendingContactWinnerFor();
        app(WinnerLifecycleService::class)->transition($winner, WinnerStatus::Contacted, 'test setup');

        return $winner->fresh();
    }

    // 1. Unauthenticated access.
    public function test_unauthenticated_requests_are_rejected(): void
    {
        $winner = $this->pendingContactWinnerFor();

        $this->getJson('/api/v1/account/winner')->assertUnauthorized();
        $this->getJson('/api/v1/account/winners')->assertUnauthorized();
        $this->getJson("/api/v1/account/winners/{$winner->id}")->assertUnauthorized();
        $this->getJson("/api/v1/account/winners/{$winner->id}/claim")->assertUnauthorized();
        $this->postJson("/api/v1/account/winners/{$winner->id}/claim")->assertUnauthorized();
    }

    // 2. Authenticated non-winner sees an empty state, not an error.
    public function test_authenticated_non_winner_sees_empty_state(): void
    {
        $nonWinner = User::factory()->create();

        $response = $this->actingAs($nonWinner)->getJson('/api/v1/account/winner');

        $response->assertOk()->assertJsonPath('data', null);
    }

    // 3. Cross-user winner access.
    public function test_cross_user_winner_access_returns_404(): void
    {
        $intruder = User::factory()->create();
        $winner = $this->pendingContactWinnerFor();

        $this->actingAs($intruder)->getJson("/api/v1/account/winners/{$winner->id}")->assertNotFound();
    }

    // 4. Cross-user claim access.
    public function test_cross_user_claim_access_returns_404(): void
    {
        $intruder = User::factory()->create();
        $winner = $this->contactedWinner();
        app(WinnerClaimService::class)->submit($winner, 'my notes');

        $this->actingAs($intruder)->getJson("/api/v1/account/winners/{$winner->id}/claim")->assertNotFound();
    }

    // 5. Non-winner claim submission.
    public function test_non_winner_cannot_submit_a_claim(): void
    {
        $intruder = User::factory()->create();
        $winner = $this->contactedWinner();

        $this->actingAs($intruder)
            ->postJson("/api/v1/account/winners/{$winner->id}/claim", ['notes' => 'not mine'])
            ->assertNotFound();

        $this->assertDatabaseCount('winner_claims', 0);
    }

    // 6-10. Tampering with authoritative identity fields is silently ignored.
    public function test_authoritative_fields_in_claim_body_are_ignored(): void
    {
        $winner = $this->contactedWinner();
        $otherUser = User::factory()->create();
        $otherWinner = $this->pendingContactWinnerFor($otherUser);

        $response = $this->actingAs($winner->user)->postJson("/api/v1/account/winners/{$winner->id}/claim", [
            'notes' => 'legitimate note',
            'winner_id' => $otherWinner->id,
            'user_id' => $otherUser->id,
            'ticket_id' => 999999,
            'competition_id' => 999999,
            'draw_id' => 999999,
            'prize_id' => 999999,
            'status' => WinnerClaimStatus::Approved->value,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('winner_claims', [
            'winner_id' => $winner->id,
            'status' => WinnerClaimStatus::Submitted->value,
            'notes' => 'legitimate note',
        ]);
        // The tampered target winner must NOT have gained a claim.
        $this->assertDatabaseMissing('winner_claims', ['winner_id' => $otherWinner->id]);
    }

    // 11. Status tampering is confirmed rejected even on its own.
    public function test_claim_status_cannot_be_set_directly(): void
    {
        $winner = $this->contactedWinner();

        $this->actingAs($winner->user)->postJson("/api/v1/account/winners/{$winner->id}/claim", [
            'status' => 'approved',
        ])->assertCreated();

        $this->assertDatabaseHas('winner_claims', [
            'winner_id' => $winner->id,
            'status' => WinnerClaimStatus::Submitted->value,
        ]);
    }

    // 12. No delivery-mutation route exists at all for a customer.
    public function test_no_delivery_mutation_route_exists(): void
    {
        $winner = $this->contactedWinner();

        $this->actingAs($winner->user)->postJson("/api/v1/account/winners/{$winner->id}/delivery", [])->assertStatus(404);
        $this->actingAs($winner->user)->patchJson("/api/v1/account/winners/{$winner->id}/delivery", ['status' => 'delivered'])->assertStatus(404);
    }

    // 13. No direct-completion route exists.
    public function test_no_direct_completion_route_exists(): void
    {
        $winner = $this->contactedWinner();

        $this->actingAs($winner->user)->postJson("/api/v1/account/winners/{$winner->id}/complete", [])->assertStatus(404);
        $this->actingAs($winner->user)->patchJson("/api/v1/account/winners/{$winner->id}", ['status' => 'completed'])->assertStatus(405);
    }

    // 14. Submitting a claim (the one customer action) never
    // self-verifies — it only ever reaches verification_pending.
    public function test_submitting_a_claim_never_self_verifies(): void
    {
        $winner = $this->contactedWinner();

        $this->actingAs($winner->user)->postJson("/api/v1/account/winners/{$winner->id}/claim", [])->assertCreated();

        $this->assertSame(WinnerStatus::VerificationPending, $winner->fresh()->status);
    }

    // 15. Private data leakage.
    public function test_winner_resource_never_leaks_another_users_data_or_internal_fields(): void
    {
        $winner = $this->contactedWinner();
        app(WinnerClaimService::class)->submit($winner, 'private notes about my address');

        $response = $this->actingAs($winner->user)->getJson("/api/v1/account/winners/{$winner->id}");
        $response->assertOk();

        $json = $response->json('data');
        $this->assertArrayNotHasKey('user_id', $json);
        $this->assertArrayNotHasKey('ticket_id', $json);
        $this->assertArrayNotHasKey('draw_id', $json);
        $this->assertArrayNotHasKey('metadata', $json);

        // Other users' listings must never include this winner.
        $intruder = User::factory()->create();
        $listing = $this->actingAs($intruder)->getJson('/api/v1/account/winners');
        $listing->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_prize_is_visible_before_any_delivery_exists(): void
    {
        // The fixture's competition has exactly one prize (see
        // CreatesWinnerFixtures) — unambiguous, so it should be visible
        // to the customer immediately, long before claim_approved ->
        // delivery_pending -> a prize_deliveries row exists.
        $winner = $this->pendingContactWinnerFor();

        $response = $this->actingAs($winner->user)->getJson("/api/v1/account/winners/{$winner->id}");

        $response->assertOk()->assertJsonPath('data.prize.title', $winner->competition->prizes->first()->title);
    }

    public function test_winners_index_only_returns_the_authenticated_users_own_winners(): void
    {
        $owner = $this->pendingContactWinnerFor()->user;
        $intruder = User::factory()->create();

        $response = $this->actingAs($intruder)->getJson('/api/v1/account/winners');

        $response->assertOk()->assertJsonCount(0, 'data');
    }
}
