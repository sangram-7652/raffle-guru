<?php

namespace Tests\Feature\Draw;

use App\Enums\CompetitionStatus;
use App\Models\Competition;
use App\Models\DrawResult;
use App\Models\Entry;
use App\Models\User;
use App\Services\Draw\DrawService;
use Filament\Http\Middleware\Authenticate as FilamentAuthenticate;
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

    /**
     * Amended in P11.8 (see docs/DRAW_ENGINE_P9.md §12): there is still no
     * public or customer HTTP path into the draw engine. The one
     * authorized exception is the admin panel — its routes sit behind
     * the `admin` guard (Filament's panel Authenticate middleware) and
     * the "Run draw" action is gated by DrawPolicy::run (draw.run).
     */
    public function test_no_route_outside_the_admin_panel_exists_for_draws(): void
    {
        foreach (Route::getRoutes() as $route) {
            if (! str_contains(strtolower($route->uri()), 'draw')) {
                continue;
            }

            $middleware = $route->gatherMiddleware();

            $this->assertContains('panel:admin', $middleware, "Route \"{$route->uri()}\" mentions draws but is not an admin panel route.");
            $this->assertContains(FilamentAuthenticate::class, $middleware, "Admin route \"{$route->uri()}\" must require admin authentication.");
        }
    }

    /**
     * The code-level form of the same invariant: DrawService is reachable
     * only from the queue job, the CLI command, the admin panel's
     * policy-gated RunDrawAction, and P9's own services.
     */
    public function test_draw_service_is_only_reachable_from_approved_entry_points(): void
    {
        $allowed = [
            'app/Jobs/DrawCompetitionJob.php',
            'app/Console/Commands/CompetitionDrawCommand.php',
            'app/Filament/Resources/Draws/Actions/RunDrawAction.php',
            // Container wiring only (binds the DrawStrategy) — matched by
            // its class name, never invokes a draw.
            'app/Providers/DrawServiceProvider.php',
        ];

        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace(base_path().'/', '', $file->getPathname());

            if (str_starts_with($relative, 'app/Services/Draw/') || in_array($relative, $allowed, true)) {
                continue;
            }

            $code = collect(token_get_all(file_get_contents($file->getPathname())))
                ->reject(fn ($token) => is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true))
                ->map(fn ($token) => is_array($token) ? $token[1] : $token)
                ->implode('');

            if (str_contains($code, 'DrawService')) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame([], $offenders, 'DrawService referenced from an unapproved entry point.');
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

        $entryUserId = Entry::query()->where('competition_id', $competition->id)->value('user_id');
        $user = User::query()->findOrFail($entryUserId);

        $response = $this->actingAs($user)->getJson('/api/v1/entries')->assertOk();
        $entry = collect($response->json('data'))->firstWhere('competition.id', $competition->id);

        $this->assertSame('drawn', $entry['competition']['status']);
        $this->assertArrayNotHasKey('is_winner', $entry);
        $this->assertArrayNotHasKey('winning_ticket_number', $entry);
    }
}
