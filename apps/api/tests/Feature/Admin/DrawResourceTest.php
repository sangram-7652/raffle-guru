<?php

namespace Tests\Feature\Admin;

use App\Enums\CompetitionStatus;
use App\Enums\DrawStatus;
use App\Filament\Resources\Draws\DrawResource;
use App\Filament\Resources\Draws\Pages\ListDraws;
use App\Filament\Resources\Draws\Pages\ViewDraw;
use App\Models\Admin;
use App\Models\Competition;
use App\Models\Draw;
use App\Models\DrawAuditLog;
use App\Models\Ticket;
use App\Models\User;
use App\Policies\DrawPolicy;
use App\Services\Draw\DrawService;
use App\Services\Draw\NoEligibleTicketsException;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Feature\Draw\Concerns\CreatesDrawFixtures;
use Tests\TestCase;
use Throwable;

/**
 * P11.8 — DrawResource read side: access, read-only enforcement, and
 * what the list/view show. Draws here are produced by the REAL
 * DrawService, never hand-built results.
 */
class DrawResourceTest extends TestCase
{
    use CreatesDrawFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function completedDraw(int $eligible = 5): Draw
    {
        $fixture = $this->createCompetitionWithTickets(CompetitionStatus::DrawPending, $eligible);

        return app(DrawService::class)->run($fixture['competition']);
    }

    private function failedDraw(string $exceptionClass, string $reason): Draw
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::DrawInProgress)->create();
        $draw = Draw::factory()->create([
            'competition_id' => $competition->id,
            'status' => DrawStatus::Failed,
            'failed_at' => now(),
            'failure_reason' => $reason,
        ]);
        DrawAuditLog::query()->create([
            'draw_id' => $draw->id,
            'competition_id' => $competition->id,
            'event' => 'draw.failed',
            'metadata' => ['exception' => $exceptionClass, 'message' => $reason],
        ]);

        return $draw;
    }

    // ---- Access -----------------------------------------------------

    public function test_guest_is_redirected_to_admin_login(): void
    {
        $draw = $this->completedDraw();

        $this->get('/admin/draws')->assertRedirect('/admin/login');
        $this->get("/admin/draws/{$draw->id}")->assertRedirect('/admin/login');
    }

    public function test_customer_session_is_not_an_admin_session(): void
    {
        $draw = $this->completedDraw();

        $this->actingAs(User::factory()->create(), 'web')->get('/admin/draws')->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create(), 'web')->get("/admin/draws/{$draw->id}")->assertRedirect('/admin/login');
    }

    public function test_inactive_admin_is_denied(): void
    {
        $draw = $this->completedDraw();
        $this->actingAs(Admin::factory()->inactive()->create(), 'admin');

        $this->get('/admin/draws')->assertForbidden();
        $this->get("/admin/draws/{$draw->id}")->assertForbidden();
    }

    public function test_operations_and_super_admin_can_list_and_view(): void
    {
        $draw = $this->completedDraw();

        foreach ([Admin::factory()->create(), Admin::factory()->superAdmin()->create()] as $admin) {
            $this->actingAs($admin, 'admin');

            $this->get('/admin/draws')->assertOk()->assertSee($draw->competition->title);
            $this->get("/admin/draws/{$draw->id}")->assertOk()->assertSee((string) $draw->result->ticket_number);
        }
    }

    public function test_admin_denied_by_draw_policy_gets_403(): void
    {
        Gate::policy(Draw::class, DenyDrawViewPolicy::class);
        $draw = $this->completedDraw();
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        $this->get('/admin/draws')->assertForbidden();
        $this->get("/admin/draws/{$draw->id}")->assertForbidden();
    }

    // ---- Read-only enforcement --------------------------------------

    public function test_no_create_edit_delete_routes_capabilities_or_bulk_actions(): void
    {
        $draw = $this->completedDraw();
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        $this->assertSame(['index', 'view'], array_keys(DrawResource::getPages()));
        $this->get('/admin/draws/create')->assertNotFound();
        $this->get("/admin/draws/{$draw->id}/edit")->assertNotFound();

        $this->assertTrue(DrawResource::canViewAny());
        foreach (['canEdit', 'canDelete', 'canForceDelete', 'canRestore', 'canReplicate'] as $method) {
            $this->assertFalse(DrawResource::{$method}($draw), $method);
        }
        $this->assertFalse(DrawResource::canCreate());
        $this->assertFalse(DrawResource::canDeleteAny());

        $table = Livewire::test(ListDraws::class)->instance()->getTable();
        $this->assertSame(['view'], array_keys($table->getFlatActions()));
        $this->assertSame([], $table->getBulkActions());

        $this->assertSame([], Livewire::test(ViewDraw::class, ['record' => $draw->id])->instance()->getCachedHeaderActions());
    }

    public function test_crafted_livewire_requests_cannot_mutate_a_draw_or_its_result(): void
    {
        $draw = $this->completedDraw();
        $otherTicket = Ticket::query()->where('id', '!=', $draw->result->ticket_id)->firstOrFail();
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        $before = [
            DB::table('draws')->orderBy('id')->get(),
            DB::table('draw_results')->orderBy('id')->get(),
            DB::table('competitions')->where('id', $draw->competition_id)->value('status'),
        ];

        $attempts = [
            fn () => Livewire::test(ViewDraw::class, ['record' => $draw->id])->set('record.status', DrawStatus::Failed->value),
            fn () => Livewire::test(ViewDraw::class, ['record' => $draw->id])->set('record.result.ticket_id', $otherTicket->id),
            fn () => Livewire::test(ViewDraw::class, ['record' => $draw->id])->set('data.status', 'running')->call('save'),
            fn () => Livewire::test(ViewDraw::class, ['record' => $draw->id])->call('mountAction', 'edit')->call('callMountedAction'),
            fn () => Livewire::test(ViewDraw::class, ['record' => $draw->id])->call('mountAction', 'delete')->call('callMountedAction'),
            fn () => Livewire::test(ListDraws::class)->call('mountTableAction', 'delete', (string) $draw->id)->call('callMountedTableAction'),
            fn () => Livewire::test(ListDraws::class)->set('selectedTableRecords', [(string) $draw->id])->call('mountTableBulkAction', 'delete')->call('callMountedTableBulkAction'),
        ];

        foreach ($attempts as $attempt) {
            try {
                $attempt();
            } catch (Throwable) {
                // Refused outright — also a pass; the DB check is what matters.
            }
        }

        $this->assertEquals($before, [
            DB::table('draws')->orderBy('id')->get(),
            DB::table('draw_results')->orderBy('id')->get(),
            DB::table('competitions')->where('id', $draw->competition_id)->value('status'),
        ]);
    }

    // ---- Content ------------------------------------------------------

    public function test_view_shows_the_real_result_competition_and_winner_handoff(): void
    {
        $draw = $this->completedDraw();
        $result = $draw->result;
        $holder = $result->ticket->user;
        $this->actingAs(Admin::factory()->create(), 'admin');

        $this->get("/admin/draws/{$draw->id}")
            ->assertOk()
            ->assertSee($draw->competition->title)
            ->assertSee("/admin/competitions/{$draw->competition_id}", false)
            ->assertSee("/admin/tickets/{$result->ticket_id}", false)
            ->assertSee($holder->name)
            ->assertSee($draw->snapshot_checksum)
            ->assertSee('csprng:random_int')
            ->assertSee('Completed')
            // P10 handoff ran (sync queue in tests): winner record exists.
            ->assertSee('Pending Contact');

        // The winning ticket shown is exactly the persisted result's.
        $this->assertSame($result->ticket_number, Ticket::query()->find($result->ticket_id)->ticket_number);
    }

    public function test_failure_reason_is_shown_only_when_domain_authored(): void
    {
        $domain = $this->failedDraw(NoEligibleTicketsException::class, 'Competition 1 has no eligible tickets to draw from.');
        $unexpected = $this->failedDraw(QueryException::class, 'SQLSTATE[HY000] secret-host select * from users');
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        $this->get("/admin/draws/{$domain->id}")->assertSee('has no eligible tickets to draw from');

        $this->get("/admin/draws/{$unexpected->id}")
            ->assertSee('Unexpected error — details are in the application logs.')
            ->assertDontSee('SQLSTATE', false)
            ->assertDontSee('secret-host', false);
    }

    public function test_audit_trail_is_visible_only_with_audit_view(): void
    {
        $draw = $this->completedDraw();

        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');
        $this->get("/admin/draws/{$draw->id}")->assertSee('Audit trail')->assertSee('draw.completed');

        $this->actingAs(Admin::factory()->create(), 'admin'); // Operations: no audit.view
        $this->get("/admin/draws/{$draw->id}")->assertDontSee('Audit trail')->assertDontSee('draw.completed');
    }

    public function test_sensitive_fields_are_never_exposed(): void
    {
        $draw = $this->completedDraw();
        $holder = $draw->result->ticket->user;
        DB::table('users')->where('id', $holder->id)->update(['remember_token' => 'REMEMBER-TOKEN-SENTINEL']);
        $hash = DB::table('users')->where('id', $holder->id)->value('password');
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        foreach (['/admin/draws', "/admin/draws/{$draw->id}"] as $url) {
            $this->get($url)->assertOk()->assertDontSee($hash, false)->assertDontSee('REMEMBER-TOKEN-SENTINEL', false);
        }

        $view = Livewire::test(ViewDraw::class, ['record' => $draw->id])->instance();
        $this->assertSame(['id', 'name', 'email'], array_keys($view->getRecord()->result->ticket->user->getAttributes()));
    }

    // ---- Table ------------------------------------------------------

    public function test_filters_and_search(): void
    {
        $completed = $this->completedDraw();
        $failed = $this->failedDraw(NoEligibleTicketsException::class, 'none');
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(ListDraws::class)
            ->filterTable('status', [DrawStatus::Failed->value])
            ->assertCanSeeTableRecords([$failed])
            ->assertCanNotSeeTableRecords([$completed]);

        Livewire::test(ListDraws::class)
            ->filterTable('has_result', true)
            ->assertCanSeeTableRecords([$completed])
            ->assertCanNotSeeTableRecords([$failed]);

        Livewire::test(ListDraws::class)
            ->filterTable('competition', ['competition_id' => $failed->competition_id])
            ->assertCanSeeTableRecords([$failed])
            ->assertCanNotSeeTableRecords([$completed]);

        Livewire::test(ListDraws::class)
            ->searchTable("#{$failed->id}")
            ->assertCanSeeTableRecords([$failed])
            ->assertCanNotSeeTableRecords([$completed]);
    }

    public function test_search_by_winning_ticket_number(): void
    {
        $draw = $this->completedDraw(eligible: 3);
        $number = $draw->result->ticket_number;

        // A numeric term also matches a draw with that ID (inclusive
        // search) — so compare against a draw whose ID differs from the
        // randomly drawn number, otherwise this test depends on luck.
        do {
            $other = $this->failedDraw(NoEligibleTicketsException::class, 'none');
        } while ($other->id === $number);
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(ListDraws::class)
            ->searchTable((string) $number)
            ->assertCanSeeTableRecords([$draw])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_list_and_view_query_counts_do_not_grow(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');

        $count = function (callable $fn): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $fn();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $small = $this->completedDraw(eligible: 2);
        $listFew = $count(fn () => Livewire::test(ListDraws::class));
        $viewSmall = $count(fn () => Livewire::test(ViewDraw::class, ['record' => $small->id]));

        foreach (range(1, 6) as $i) {
            $this->completedDraw(eligible: 3);
        }
        $large = $this->completedDraw(eligible: 200);

        $this->assertSame($listFew, $count(fn () => Livewire::test(ListDraws::class)), 'Draw list N+1');
        // The view never loads the competition's tickets.
        $this->assertSame($viewSmall, $count(fn () => Livewire::test(ViewDraw::class, ['record' => $large->id])), 'Draw view grows with ticket count');
    }

    public function test_navigation_group(): void
    {
        $this->assertSame('Tickets & Draws', DrawResource::getNavigationGroup());
    }
}

class DenyDrawViewPolicy extends DrawPolicy
{
    public function viewAny(Admin $admin): bool
    {
        return false;
    }

    public function view(Admin $admin, Draw $draw): bool
    {
        return false;
    }
}
