<?php

namespace Tests\Feature\Admin;

use App\Enums\CompetitionStatus;
use App\Enums\DrawStatus;
use App\Filament\Resources\Draws\Pages\ListDraws;
use App\Models\Admin;
use App\Models\Competition;
use App\Models\CompetitionLifecycleEvent;
use App\Models\Draw;
use App\Models\DrawAuditLog;
use App\Models\DrawResult;
use App\Models\Ticket;
use App\Models\Winner;
use App\Policies\DrawPolicy;
use App\Services\Draw\DrawService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Feature\Draw\Concerns\CreatesDrawFixtures;
use Tests\TestCase;

/**
 * P11.8 — the "Run draw" action. The primary tests run the REAL P9 path
 * end to end (DrawService -> eligibility snapshot -> CSPRNG strategy ->
 * DrawResult -> lifecycle -> P10 winner handoff); nothing is mocked
 * except where a test deliberately simulates a concurrent trigger.
 */
class RunDrawActionTest extends TestCase
{
    use CreatesDrawFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /**
     * Raw Livewire calls, as a crafted request would make them — the
     * data array is sent verbatim, extra keys included.
     *
     * @param  array<string, mixed>  $data
     */
    private function submitRunDraw(array $data): Testable
    {
        $component = Livewire::test(ListDraws::class)->call('mountAction', 'runDraw');

        foreach ($data as $key => $value) {
            $component->set("mountedActions.0.data.{$key}", $value);
        }

        return $component->call('callMountedAction');
    }

    public function test_authorized_admin_runs_the_real_draw_end_to_end(): void
    {
        $fixture = $this->createCompetitionWithTickets(CompetitionStatus::DrawPending, eligibleCount: 20, refundedCount: 5);
        $competition = $fixture['competition'];
        $this->actingAs(Admin::factory()->create(), 'admin'); // Operations holds draw.run (P11.2)

        Livewire::test(ListDraws::class)
            ->callAction('runDraw', data: ['competition_id' => $competition->id])
            ->assertHasNoActionErrors()
            ->assertNotified('Draw completed');

        $competition->refresh();
        $result = DrawResult::query()->where('competition_id', $competition->id)->sole();
        $draw = Draw::query()->where('competition_id', $competition->id)->sole();

        // Real P9 outcome.
        $this->assertSame(CompetitionStatus::Drawn, $competition->status);
        $this->assertSame(DrawStatus::Completed, $draw->status);
        $this->assertSame($draw->id, $result->draw_id);
        $this->assertContains($result->ticket_number, $fixture['eligibleTicketNumbers']);
        $this->assertNotContains($result->ticket_number, $fixture['ineligibleTicketNumbers']);
        $this->assertSame(20, $result->eligible_ticket_count);
        $this->assertSame('csprng:random_int', $draw->randomness_method);
        $this->assertNotNull($draw->snapshot_checksum);

        // Result ticket relationship is coherent.
        $ticket = Ticket::query()->findOrFail($result->ticket_id);
        $this->assertSame($competition->id, $ticket->competition_id);
        $this->assertSame($result->ticket_number, $ticket->ticket_number);

        // Normal P9 audit trail and P8 lifecycle audit.
        $this->assertSame(['draw.started', 'draw.completed'], DrawAuditLog::query()->where('draw_id', $draw->id)->orderBy('id')->pluck('event')->all());
        $this->assertSame(
            [CompetitionStatus::DrawInProgress->value, CompetitionStatus::Drawn->value],
            CompetitionLifecycleEvent::query()->where('competition_id', $competition->id)->orderBy('id')->pluck('to_status')->all(),
        );

        // P10 winner creation ran through the existing event/job flow.
        $winner = Winner::query()->where('competition_id', $competition->id)->sole();
        $this->assertSame($result->id, $winner->draw_result_id);
        $this->assertSame($ticket->user_id, $winner->user_id);
    }

    public function test_super_admin_can_run_a_draw(): void
    {
        $competition = $this->createCompetitionWithTickets(CompetitionStatus::DrawPending, 3)['competition'];
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        Livewire::test(ListDraws::class)->callAction('runDraw', data: ['competition_id' => $competition->id]);

        $this->assertSame(CompetitionStatus::Drawn, $competition->fresh()->status);
    }

    public function test_the_action_asks_only_which_competition_to_draw(): void
    {
        $this->createCompetitionWithTickets(CompetitionStatus::DrawPending, 3);
        $this->actingAs(Admin::factory()->create(), 'admin');

        $component = Livewire::test(ListDraws::class)->mountAction('runDraw');
        $livewire = $component->instance();
        $fields = array_keys($livewire->{$livewire->getMountedActionSchemaName()}->getFlatFields());

        $this->assertSame(['competition_id'], $fields);
    }

    public function test_only_competitions_awaiting_a_draw_are_offered(): void
    {
        $pending = $this->createCompetitionWithTickets(CompetitionStatus::DrawPending, 2)['competition'];
        Competition::factory()->withStatus(CompetitionStatus::Active)->create();
        Competition::factory()->withStatus(CompetitionStatus::Drawn)->create();
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(ListDraws::class)
            ->mountAction('runDraw')
            ->assertFormFieldExists('competition_id', fn ($field): bool => array_keys($field->getOptions()) === [$pending->id]);

    }

    public function test_action_is_disabled_when_nothing_awaits_a_draw(): void
    {
        Competition::factory()->withStatus(CompetitionStatus::Active)->create();
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(ListDraws::class)->assertActionDisabled('runDraw');
    }

    public function test_admin_without_draw_run_cannot_see_or_invoke_it(): void
    {
        Gate::policy(Draw::class, DenyDrawRunPolicy::class);
        $competition = $this->createCompetitionWithTickets(CompetitionStatus::DrawPending, 3)['competition'];
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        Livewire::test(ListDraws::class)->assertActionHidden('runDraw');
        $this->submitRunDraw(['competition_id' => $competition->id]);

        $this->assertSame(CompetitionStatus::DrawPending, $competition->fresh()->status);
        $this->assertSame(0, Draw::query()->count());
        $this->assertSame(0, DrawResult::query()->count());
    }

    public function test_ineligible_competitions_are_rejected(): void
    {
        $active = $this->createCompetitionWithTickets(CompetitionStatus::Active, 3)['competition'];
        $drawn = Competition::factory()->withStatus(CompetitionStatus::Drawn)->create();
        $this->createCompetitionWithTickets(CompetitionStatus::DrawPending, 1); // so the action is enabled
        $this->actingAs(Admin::factory()->create(), 'admin');

        foreach ([$active, $drawn] as $competition) {
            $this->submitRunDraw(['competition_id' => $competition->id])
                ->assertHasActionErrors(['competition_id']);
        }

        $this->assertSame(CompetitionStatus::Active, $active->fresh()->status);
        $this->assertSame(CompetitionStatus::Drawn, $drawn->fresh()->status);
        $this->assertSame(0, Draw::query()->count());
    }

    public function test_no_eligible_tickets_is_reported_as_the_domain_rejection(): void
    {
        $competition = $this->createCompetitionWithTickets(CompetitionStatus::DrawPending, eligibleCount: 0, refundedCount: 4)['competition'];
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(ListDraws::class)
            ->callAction('runDraw', data: ['competition_id' => $competition->id])
            ->assertNotified('Draw failed');

        $draw = Draw::query()->where('competition_id', $competition->id)->sole();
        $this->assertSame(DrawStatus::Failed, $draw->status);
        $this->assertSame(0, DrawResult::query()->count());
        $this->assertSame(0, Winner::query()->count());
        // DrawService leaves it in progress for recovery — never forced.
        $this->assertSame(CompetitionStatus::DrawInProgress, $competition->fresh()->status);
    }

    public function test_running_twice_produces_exactly_one_draw_and_one_winner(): void
    {
        $competition = $this->createCompetitionWithTickets(CompetitionStatus::DrawPending, 10)['competition'];
        $this->actingAs(Admin::factory()->create(), 'admin');

        // Double-click: two requests, the second after the first finished.
        $this->submitRunDraw(['competition_id' => $competition->id]);
        $this->submitRunDraw(['competition_id' => $competition->id]);

        $this->assertSame(1, Draw::query()->where('competition_id', $competition->id)->count());
        $this->assertSame(1, DrawResult::query()->where('competition_id', $competition->id)->count());
        $this->assertSame(1, Winner::query()->where('competition_id', $competition->id)->count());
    }

    /**
     * P11.8 §12 — the admin saw draw_pending and submitted, but the
     * scheduler's DrawCompetitionJob won the race between validation and
     * the service's row lock. DrawService re-reads the locked row, finds
     * it already drawn and no-ops; the admin is told the truth.
     */
    public function test_draw_started_elsewhere_before_the_lock_is_not_duplicated(): void
    {
        $competition = $this->createCompetitionWithTickets(CompetitionStatus::DrawPending, 10)['competition'];
        $this->actingAs(Admin::factory()->create(), 'admin');

        $this->app->instance(DrawService::class, new class(app(DrawService::class)) extends DrawService
        {
            public function __construct(private readonly DrawService $real) {}

            public function run(Competition $competition): ?Draw
            {
                $this->real->run($competition); // the scheduler gets there first

                return $this->real->run($competition); // the admin's call
            }
        });

        Livewire::test(ListDraws::class)
            ->callAction('runDraw', data: ['competition_id' => $competition->id])
            ->assertNotified('Nothing was run');

        $this->assertSame(1, DrawResult::query()->where('competition_id', $competition->id)->count());
        $this->assertSame(1, Draw::query()->where('competition_id', $competition->id)->count());
    }

    public function test_a_live_in_progress_attempt_is_not_stolen(): void
    {
        $competition = $this->createCompetitionWithTickets(CompetitionStatus::DrawInProgress, 5)['competition'];
        Draw::factory()->create([
            'competition_id' => $competition->id,
            'status' => DrawStatus::Running,
            'attempt' => 1,
            'started_at' => now()->subMinute(), // well within the lease
        ]);
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(ListDraws::class)
            ->callAction('runDraw', data: ['competition_id' => $competition->id])
            ->assertNotified('Nothing was run');

        $this->assertSame(1, Draw::query()->where('competition_id', $competition->id)->count());
        $this->assertSame(0, DrawResult::query()->count());
    }

    /**
     * P11.8 §26–§28 — forged winner/ticket/randomness inputs. The forged
     * ticket is a REFUNDED (ineligible) ticket in the same competition,
     * so if the forgery were honoured it would be detectable with
     * certainty; P9 can never select it.
     */
    public function test_forged_ticket_winner_and_rng_inputs_are_ignored(): void
    {
        $fixture = $this->createCompetitionWithTickets(CompetitionStatus::DrawPending, eligibleCount: 10, refundedCount: 3);
        $competition = $fixture['competition'];
        $forgedTicket = Ticket::query()
            ->where('competition_id', $competition->id)
            ->where('ticket_number', $fixture['ineligibleTicketNumbers'][0])
            ->sole();
        $this->actingAs(Admin::factory()->create(), 'admin');

        $this->submitRunDraw([
            'competition_id' => $competition->id,
            'ticket_id' => $forgedTicket->id,
            'winning_ticket' => $forgedTicket->ticket_number,
            'ticket_number' => $forgedTicket->ticket_number,
            'winner_id' => $forgedTicket->user_id,
            'user_id' => $forgedTicket->user_id,
            'random_number' => $forgedTicket->ticket_number,
            'random_seed' => 42,
            'rng' => 'fixed',
            'offset' => 0,
            'snapshot_checksum' => 'forged',
        ]);

        $result = DrawResult::query()->where('competition_id', $competition->id)->sole();
        $draw = Draw::query()->findOrFail($result->draw_id);

        $this->assertNotSame($forgedTicket->id, $result->ticket_id);
        $this->assertContains($result->ticket_number, $fixture['eligibleTicketNumbers']);
        $this->assertNotSame('forged', $draw->snapshot_checksum);
        $this->assertSame('csprng:random_int', $draw->randomness_method);
        $this->assertNotSame($forgedTicket->user_id, Winner::query()->where('competition_id', $competition->id)->value('user_id'));
    }
}

class DenyDrawRunPolicy extends DrawPolicy
{
    public function run(Admin $admin): bool
    {
        return false;
    }
}
