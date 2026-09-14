<?php

namespace Tests\Feature\Draw;

use App\Enums\CompetitionStatus;
use App\Models\Competition;
use App\Models\DrawResult;
use App\Models\User;
use App\Services\Draw\DrawService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Draw\Concerns\CreatesDrawFixtures;
use Tests\TestCase;

/**
 * Test matrix items 34-37 plus the P9 security checklist: no route
 * exists to trigger a draw, the draw result cannot be mutated through
 * the public API, competition isolation holds, and no internal draw
 * data leaks through CompetitionResource/EntryResource.
 */
class DrawApiTest extends TestCase
{
    use CreatesDrawFixtures, RefreshDatabase;

    public function test_no_route_exists_that_can_trigger_a_draw(): void
    {
        $names = collect(Route::getRoutes())->map(fn ($r) => $r->uri());

        foreach ($names as $uri) {
            $this->assertStringNotContainsString('draw', strtolower($uri), "Route \"{$uri}\" must not exist — the draw engine has no HTTP entry point at all.");
        }
    }

    public function test_an_unauthenticated_request_cannot_reach_any_draw_functionality(): void
    {
        // There is no endpoint to even attempt this against — assert
        // the obvious candidates 404, confirming nothing was
        // accidentally wired up.
        $this->postJson('/api/v1/competitions/1/draw')->assertNotFound();
        $this->postJson('/api/v1/draws')->assertNotFound();
    }

    public function test_an_authenticated_customer_cannot_reach_any_draw_functionality_either(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/competitions/1/draw')->assertNotFound();
        $this->actingAs($user)->postJson('/api/v1/draws')->assertNotFound();
    }

    public function test_drawn_competition_exposes_only_the_minimal_public_draw_fields(): void
    {
        ['competition' => $competition] = $this->createCompetitionWithTickets(CompetitionStatus::DrawPending, 3);
        $draw = app(DrawService::class)->run($competition);

        $response = $this->getJson("/api/v1/competitions/{$competition->slug}")->assertOk();

        $response->assertJsonPath('data.status', 'drawn');
        $response->assertJsonPath('data.draw.ticket_number', $draw->result->ticket_number);
        $response->assertJsonStructure(['data' => ['draw' => ['ticket_number', 'drawn_at']]]);

        // Nothing internal leaks: no draw id, strategy, RNG method,
        // checksum, attempt history, or winner identity.
        $json = $response->json('data.draw');
        $this->assertArrayNotHasKey('id', $json);
        $this->assertArrayNotHasKey('randomness_method', $json);
        $this->assertArrayNotHasKey('snapshot_checksum', $json);
        $this->assertArrayNotHasKey('eligible_ticket_count', $json);
        $this->assertArrayNotHasKey('user_id', $json);
        $this->assertArrayNotHasKey('type', $json);
    }

    public function test_a_not_yet_drawn_competition_has_no_draw_field_at_all(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create();

        $response = $this->getJson("/api/v1/competitions/{$competition->slug}")->assertOk();

        $this->assertArrayNotHasKey('draw', $response->json('data'));
    }

    public function test_the_draw_result_cannot_be_mutated_through_any_public_api(): void
    {
        ['competition' => $competition] = $this->createCompetitionWithTickets(CompetitionStatus::DrawPending, 3);
        $draw = app(DrawService::class)->run($competition);
        $result = DrawResult::query()->where('competition_id', $competition->id)->firstOrFail();

        // No route accepts a PATCH/PUT/POST for competitions at all
        // (see CompetitionController — read-only, index/show only): the
        // URI exists for GET, so a write verb correctly gets 405, not a
        // silently-accepted write.
        $this->patchJson("/api/v1/competitions/{$competition->slug}", ['status' => 'active'])->assertMethodNotAllowed();
        $this->putJson("/api/v1/competitions/{$competition->slug}", ['status' => 'active'])->assertMethodNotAllowed();

        $this->assertSame($result->ticket_number, $result->fresh()->ticket_number);
        $this->assertSame(CompetitionStatus::Drawn, $competition->fresh()->status);
    }

    /**
     * Each user's own entries correctly reflect their competition's
     * lifecycle status (P9's minimal read-only account-page addition)
     * without leaking anything about who won.
     */
    public function test_entries_api_reflects_drawn_status_without_revealing_winner_identity(): void
    {
        ['competition' => $competition] = $this->createCompetitionWithTickets(CompetitionStatus::DrawPending, 3);
        app(DrawService::class)->run($competition);

        $entryUserId = \App\Models\Entry::query()->where('competition_id', $competition->id)->value('user_id');
        $user = User::query()->findOrFail($entryUserId);

        $response = $this->actingAs($user)->getJson('/api/v1/entries')->assertOk();
        $entry = collect($response->json('data'))->firstWhere('competition.id', $competition->id);

        $this->assertSame('drawn', $entry['competition']['status']);
        $this->assertArrayNotHasKey('is_winner', $entry);
        $this->assertArrayNotHasKey('winning_ticket_number', $entry);
    }
}
