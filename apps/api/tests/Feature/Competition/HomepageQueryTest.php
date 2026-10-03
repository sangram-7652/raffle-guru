<?php

namespace Tests\Feature\Competition;

use App\Enums\CompetitionStatus;
use App\Models\Competition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P11.9 — the two homepage sections are served by the existing public
 * listing query (no homepage-specific endpoint). These pin the semantics
 * the frontend relies on: "featured" = is_featured + open for entries,
 * "ending soon" = open for entries, soonest draw first.
 */
class HomepageQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_featured_live_competitions_are_returned_soonest_draw_first(): void
    {
        $later = Competition::factory()->withStatus(CompetitionStatus::Active)->create([
            'is_featured' => true, 'draw_at' => now()->addDays(9),
        ]);
        $sooner = Competition::factory()->withStatus(CompetitionStatus::Active)->create([
            'is_featured' => true, 'draw_at' => now()->addDays(2),
        ]);
        Competition::factory()->withStatus(CompetitionStatus::Active)->create([
            'is_featured' => false, 'draw_at' => now()->addDay(),
        ]);

        $slugs = $this->getJson('/api/v1/competitions?featured=1&status=active&sort=ending_soon&per_page=4')
            ->assertOk()
            ->json('data.*.slug');

        $this->assertSame([$sooner->slug, $later->slug], $slugs);
    }

    public function test_ending_soon_is_open_entries_only_and_capped_by_per_page(): void
    {
        $soonest = Competition::factory()->withStatus(CompetitionStatus::Active)->create(['draw_at' => now()->addDay()]);
        $second = Competition::factory()->withStatus(CompetitionStatus::Active)->create(['draw_at' => now()->addDays(2)]);
        Competition::factory()->withStatus(CompetitionStatus::Active)->create(['draw_at' => now()->addDays(3)]);
        // Earlier draw dates, but not open for entries: must not appear.
        Competition::factory()->withStatus(CompetitionStatus::SoldOut)->create(['draw_at' => now()->addHour()]);
        Competition::factory()->withStatus(CompetitionStatus::Drawn)->create(['draw_at' => now()->subDay()]);

        $slugs = $this->getJson('/api/v1/competitions?status=active&sort=ending_soon&per_page=2')
            ->assertOk()
            ->json('data.*.slug');

        $this->assertSame([$soonest->slug, $second->slug], $slugs);
    }

    public function test_scheduled_and_draft_competitions_never_appear_in_any_public_query(): void
    {
        Competition::factory()->withStatus(CompetitionStatus::Draft)->create(['draw_at' => now()->addDay()]);
        Competition::factory()->withStatus(CompetitionStatus::Scheduled)->create(['draw_at' => now()->addDay(), 'is_featured' => true]);

        $this->getJson('/api/v1/competitions?sort=ending_soon')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/competitions?featured=1&status=active')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/competitions?status=scheduled')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/competitions?status=draft')->assertOk()->assertJsonCount(0, 'data');
    }
}
