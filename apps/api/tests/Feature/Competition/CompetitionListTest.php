<?php

namespace Tests\Feature\Competition;

use App\Enums\CompetitionStatus;
use App\Models\Competition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CompetitionListTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_competitions_are_returned(): void
    {
        Competition::factory()->withStatus(CompetitionStatus::Active)->create();

        $response = $this->getJson('/api/v1/competitions');

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_draft_competitions_are_excluded(): void
    {
        Competition::factory()->withStatus(CompetitionStatus::Draft)->create();
        Competition::factory()->withStatus(CompetitionStatus::Active)->create();

        $response = $this->getJson('/api/v1/competitions');

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_scheduled_competitions_are_excluded(): void
    {
        Competition::factory()->withStatus(CompetitionStatus::Scheduled)->create();

        $response = $this->getJson('/api/v1/competitions');

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    #[DataProvider('publicStatusProvider')]
    public function test_non_draft_statuses_remain_visible(CompetitionStatus $status): void
    {
        Competition::factory()->withStatus($status)->create();

        $this->getJson('/api/v1/competitions')->assertJsonCount(1, 'data');
    }

    /**
     * @return array<string, array{CompetitionStatus}>
     */
    public static function publicStatusProvider(): array
    {
        return [
            'active' => [CompetitionStatus::Active],
            'sold_out' => [CompetitionStatus::SoldOut],
            'closed' => [CompetitionStatus::Closed],
            'draw_pending' => [CompetitionStatus::DrawPending],
            'draw_in_progress' => [CompetitionStatus::DrawInProgress],
            'drawn' => [CompetitionStatus::Drawn],
            'completed' => [CompetitionStatus::Completed],
        ];
    }

    public function test_soft_deleted_competitions_are_excluded(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create();
        $competition->delete();

        $this->getJson('/api/v1/competitions')->assertJsonCount(0, 'data');
    }

    public function test_pagination_is_database_backed(): void
    {
        Competition::factory()->count(15)->withStatus(CompetitionStatus::Active)->create();

        $response = $this->getJson('/api/v1/competitions?per_page=10');

        $response->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.total', 15)
            ->assertJsonPath('meta.last_page', 2);

        $this->getJson('/api/v1/competitions?per_page=10&page=2')
            ->assertJsonCount(5, 'data');
    }

    public function test_per_page_is_capped(): void
    {
        Competition::factory()->count(30)->withStatus(CompetitionStatus::Active)->create();

        $response = $this->getJson('/api/v1/competitions?per_page=999');

        $response->assertUnprocessable();
    }

    public function test_search_matches_title_or_short_description(): void
    {
        Competition::factory()->withStatus(CompetitionStatus::Active)->create(['title' => 'BMW M4 Competition']);
        Competition::factory()->withStatus(CompetitionStatus::Active)->create(['title' => 'Cash Draw']);

        $this->getJson('/api/v1/competitions?search=BMW')->assertJsonCount(1, 'data');
    }

    public function test_category_filter_works(): void
    {
        Competition::factory()->withStatus(CompetitionStatus::Active)->create(['category' => 'Cars & Bikes']);
        Competition::factory()->withStatus(CompetitionStatus::Active)->create(['category' => 'Cash']);

        $this->getJson('/api/v1/competitions?category=Cash')->assertJsonCount(1, 'data');
    }

    public function test_status_filter_works_within_the_public_set(): void
    {
        Competition::factory()->withStatus(CompetitionStatus::Active)->create();
        Competition::factory()->withStatus(CompetitionStatus::SoldOut)->create();

        $this->getJson('/api/v1/competitions?status=sold_out')->assertJsonCount(1, 'data');
    }

    public function test_status_filter_cannot_be_used_to_reach_draft_competitions(): void
    {
        Competition::factory()->withStatus(CompetitionStatus::Draft)->create();

        $this->getJson('/api/v1/competitions?status=draft')->assertJsonCount(0, 'data');
    }

    public function test_featured_filter_works(): void
    {
        Competition::factory()->featured()->withStatus(CompetitionStatus::Active)->create();
        Competition::factory()->withStatus(CompetitionStatus::Active)->create(['is_featured' => false]);

        $this->getJson('/api/v1/competitions?featured=1')->assertJsonCount(1, 'data');
    }

    public function test_sort_allow_list_rejects_unknown_values(): void
    {
        $this->getJson('/api/v1/competitions?sort=id')->assertUnprocessable();
        $this->getJson('/api/v1/competitions?sort=status')->assertUnprocessable();
    }

    public function test_sort_by_ending_soon_orders_by_draw_at_ascending(): void
    {
        $soon = Competition::factory()->withStatus(CompetitionStatus::Active)->create(['draw_at' => now()->addDays(1)]);
        $later = Competition::factory()->withStatus(CompetitionStatus::Active)->create(['draw_at' => now()->addDays(10)]);

        $response = $this->getJson('/api/v1/competitions?sort=ending_soon');

        $response->assertJsonPath('data.0.id', $soon->id)
            ->assertJsonPath('data.1.id', $later->id);
    }

    public function test_sort_by_price_supports_direction(): void
    {
        $cheap = Competition::factory()->withStatus(CompetitionStatus::Active)->create(['entry_price' => 50]);
        $expensive = Competition::factory()->withStatus(CompetitionStatus::Active)->create(['entry_price' => 500]);

        $this->getJson('/api/v1/competitions?sort=price&direction=desc')
            ->assertJsonPath('data.0.id', $expensive->id)
            ->assertJsonPath('data.1.id', $cheap->id);
    }

    public function test_invalid_status_value_is_rejected(): void
    {
        $this->getJson('/api/v1/competitions?status=not-a-real-status')->assertUnprocessable();
    }

    public function test_invalid_page_value_is_rejected(): void
    {
        $this->getJson('/api/v1/competitions?page=0')->assertUnprocessable();
        $this->getJson('/api/v1/competitions?page=-1')->assertUnprocessable();
    }

    public function test_prizes_are_eager_loaded_without_n_plus_1(): void
    {
        Competition::factory()
            ->count(5)
            ->withStatus(CompetitionStatus::Active)
            ->create()
            ->each(fn (Competition $c) => \App\Models\Prize::factory()->for($c)->create());

        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->getJson('/api/v1/competitions')->assertOk();
        $queries = \Illuminate\Support\Facades\DB::getQueryLog();
        \Illuminate\Support\Facades\DB::disableQueryLog();

        // One query for the paginated competitions, one count query, one
        // eager-load query for prizes, one eager-load query for drawResult
        // (P9) — NOT one prize/drawResult query per competition.
        $this->assertLessThanOrEqual(4, count($queries));
    }
}
