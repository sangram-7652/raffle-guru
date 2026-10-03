<?php

namespace Tests\Feature\Admin;

use App\Enums\CompetitionStatus;
use App\Filament\Resources\Competitions\Pages\CreateCompetition;
use App\Filament\Resources\Competitions\Pages\ViewCompetition;
use App\Filament\Resources\Prizes\Pages\CreatePrize;
use App\Models\Admin;
use App\Models\Competition;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * P11.9 — the whole admin-to-public chain, through the real Filament
 * pages and the real lifecycle service. Nothing here writes `status`
 * directly; the only way a competition becomes publicly visible is the
 * admin "Transition status" action, followed by the existing
 * scheduled -> active edge.
 */
class AdminToPublicFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_admin_creates_a_competition_and_prize_and_publishes_it_through_the_lifecycle(): void
    {
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        // 1. Create the competition in Admin. It is a draft.
        Livewire::test(CreateCompetition::class)
            ->fillForm([
                'title' => 'Integration Test Porsche',
                'slug' => 'integration-test-porsche',
                'short_description' => 'A brand new 911 Carrera.',
                'category' => 'Cars & Bikes',
                'entry_price' => '10.50',
                'max_entries' => 5000,
                'max_entries_per_user' => 50,
                'starts_at' => '2026-10-01 09:00:00',
                'ends_at' => '2026-10-15 21:00:00',
                'draw_at' => '2026-10-16 20:00:00',
                'is_featured' => true,
                'skill_question' => [
                    'prompt' => 'Which colour is the sky?',
                    'options' => [['option' => 'Green'], ['option' => 'Blue']],
                    'answer_index' => 1,
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $competition = Competition::query()->where('slug', 'integration-test-porsche')->firstOrFail();
        $this->assertSame(CompetitionStatus::Draft, $competition->status);

        // 2. Create its prize in Admin.
        Livewire::test(CreatePrize::class)
            ->fillForm([
                'competition_id' => $competition->id,
                'title' => 'Porsche 911 Carrera',
                'description' => [['point' => 'Registered and insured']],
                'value' => '1000.00',
                'cash_alternative_value' => '850.00',
                'quantity' => 1,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        // 3. Still invisible to customers while a draft.
        $this->getJson('/api/v1/competitions')->assertOk()->assertJsonMissing(['slug' => 'integration-test-porsche']);
        $this->getJson('/api/v1/competitions/integration-test-porsche')->assertNotFound();

        // 4. Publish: draft -> scheduled. Still not public.
        Livewire::test(ViewCompetition::class, ['record' => $competition->getRouteKey()])
            ->callAction('transitionStatus', data: [
                'to_status' => CompetitionStatus::Scheduled->value,
                'reason' => 'Publishing for the autumn launch',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(CompetitionStatus::Scheduled, $competition->fresh()->status);
        $this->getJson('/api/v1/competitions/integration-test-porsche')->assertNotFound();

        // 5. Activate: scheduled -> active. Now publicly eligible.
        Livewire::test(ViewCompetition::class, ['record' => $competition->getRouteKey()])
            ->callAction('transitionStatus', data: [
                'to_status' => CompetitionStatus::Active->value,
                'reason' => 'Opening entries',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(CompetitionStatus::Active, $competition->fresh()->status);

        // 6. The public API now returns it, with its prize, and never the answer.
        $this->getJson('/api/v1/competitions?status=active')
            ->assertOk()
            ->assertJsonFragment(['slug' => 'integration-test-porsche'])
            ->assertJsonPath('data.0.prizes.0.title', 'Porsche 911 Carrera')
            ->assertJsonPath('data.0.prizes.0.value', 100000);

        $detail = $this->getJson('/api/v1/competitions/integration-test-porsche')
            ->assertOk()
            ->assertJsonPath('data.title', 'Integration Test Porsche')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.prizes.0.cash_alternative_value', 85000)
            ->assertJsonPath('data.skill_question.prompt', 'Which colour is the sky?');

        $this->assertStringNotContainsString('answer_index', $detail->getContent());
    }

    public function test_a_draft_cannot_be_published_directly_to_active_by_the_admin_action(): void
    {
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');
        $competition = Competition::factory()->withStatus(CompetitionStatus::Draft)->create([
            'slug' => 'skip-the-schedule',
        ]);

        Livewire::test(ViewCompetition::class, ['record' => $competition->getRouteKey()])
            ->callAction('transitionStatus', data: [
                'to_status' => CompetitionStatus::Active->value,
                'reason' => 'Trying to skip scheduling',
            ])
            ->assertHasActionErrors(['to_status']);

        $this->assertSame(CompetitionStatus::Draft, $competition->fresh()->status);
        $this->getJson('/api/v1/competitions/skip-the-schedule')->assertNotFound();
    }
}
