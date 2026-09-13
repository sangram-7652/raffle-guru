<?php

namespace Tests\Feature\Competition;

use App\Enums\CompetitionStatus;
use App\Models\Competition;
use App\Models\Prize;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompetitionSerializationTest extends TestCase
{
    use RefreshDatabase;

    private const EXPECTED_COMPETITION_KEYS = [
        'id', 'slug', 'title', 'short_description', 'description', 'category',
        'status', 'entry_price', 'currency', 'max_entries', 'max_entries_per_user',
        'images', 'skill_question', 'starts_at', 'ends_at', 'draw_at',
        'is_featured', 'prizes',
    ];

    private const EXPECTED_PRIZE_KEYS = [
        'id', 'title', 'description', 'value', 'cash_alternative_value', 'currency', 'quantity',
    ];

    public function test_competition_resource_exposes_exactly_the_intended_public_fields(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create();
        Prize::factory()->for($competition)->create();

        $response = $this->getJson("/api/v1/competitions/{$competition->slug}");

        $data = $response->json('data');
        $expectedKeys = self::EXPECTED_COMPETITION_KEYS;
        sort($expectedKeys);
        $actualKeys = array_keys($data);
        sort($actualKeys);

        $this->assertSame($expectedKeys, $actualKeys);
    }

    public function test_prize_resource_exposes_exactly_the_intended_public_fields(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create();
        Prize::factory()->for($competition)->create();

        $response = $this->getJson("/api/v1/competitions/{$competition->slug}");

        $prizeKeys = array_keys($response->json('data.prizes.0'));
        sort($prizeKeys);
        $expected = self::EXPECTED_PRIZE_KEYS;
        sort($expected);

        $this->assertSame($expected, $prizeKeys);
    }

    public function test_no_internal_or_soft_delete_fields_leak(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create();

        $response = $this->getJson("/api/v1/competitions/{$competition->slug}");

        $response->assertJsonMissingPath('data.deleted_at')
            ->assertJsonMissingPath('data.created_at')
            ->assertJsonMissingPath('data.updated_at');
    }
}
