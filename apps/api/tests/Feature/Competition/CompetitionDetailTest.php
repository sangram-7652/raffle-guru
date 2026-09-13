<?php

namespace Tests\Feature\Competition;

use App\Enums\CompetitionStatus;
use App\Models\Competition;
use App\Models\Prize;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompetitionDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_slug_returns_the_competition(): void
    {
        $competition = Competition::factory()
            ->withStatus(CompetitionStatus::Active)
            ->create(['slug' => 'bmw-m4']);

        $response = $this->getJson('/api/v1/competitions/bmw-m4');

        $response->assertOk()->assertJsonPath('data.slug', 'bmw-m4');
    }

    public function test_unknown_slug_returns_404(): void
    {
        $this->getJson('/api/v1/competitions/does-not-exist')->assertNotFound();
    }

    public function test_draft_competition_is_not_publicly_retrievable_by_slug(): void
    {
        Competition::factory()
            ->withStatus(CompetitionStatus::Draft)
            ->create(['slug' => 'not-yet-published']);

        $this->getJson('/api/v1/competitions/not-yet-published')->assertNotFound();
    }

    public function test_soft_deleted_competition_is_not_publicly_retrievable(): void
    {
        $competition = Competition::factory()
            ->withStatus(CompetitionStatus::Active)
            ->create(['slug' => 'gone']);
        $competition->delete();

        $this->getJson('/api/v1/competitions/gone')->assertNotFound();
    }

    public function test_prizes_are_returned(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create(['slug' => 'has-prizes']);
        Prize::factory()->for($competition)->create(['title' => 'BMW M4']);

        $response = $this->getJson('/api/v1/competitions/has-prizes');

        $response->assertJsonPath('data.prizes.0.title', 'BMW M4');
    }

    public function test_answer_index_is_never_exposed(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create([
            'slug' => 'has-question',
            'skill_question' => [
                'prompt' => 'What colour is the sky?',
                'options' => ['Blue', 'Green'],
                'answer_index' => 0,
            ],
        ]);

        $response = $this->getJson('/api/v1/competitions/has-question');

        $response->assertJsonMissingPath('data.skill_question.answer_index');
        $response->assertJsonPath('data.skill_question.prompt', 'What colour is the sky?');
        $this->assertStringNotContainsString('answer_index', $response->getContent());
    }

    public function test_listing_never_exposes_answer_index_either(): void
    {
        Competition::factory()->withStatus(CompetitionStatus::Active)->create([
            'skill_question' => ['prompt' => 'Q', 'options' => ['A', 'B'], 'answer_index' => 1],
        ]);

        $response = $this->getJson('/api/v1/competitions');

        $this->assertStringNotContainsString('answer_index', $response->getContent());
    }
}
