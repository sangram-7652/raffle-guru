<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Admin;
use App\Models\Competition;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use App\Policies\TicketPolicy;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Feature\Admin\Concerns\BuildsAllocations;
use Tests\TestCase;
use Throwable;

/**
 * P11.7 — TicketResource: read-only inspection of immutable P7 tickets.
 */
class TicketResourceTest extends TestCase
{
    use BuildsAllocations, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function ticket(int $number = 4821): Ticket
    {
        return $this->allocate(tickets: 1, firstTicketNumber: $number)->tickets()->firstOrFail();
    }

    // ---- Access -----------------------------------------------------

    public function test_guest_is_redirected_to_admin_login(): void
    {
        $ticket = $this->ticket();

        $this->get('/admin/tickets')->assertRedirect('/admin/login');
        $this->get("/admin/tickets/{$ticket->id}")->assertRedirect('/admin/login');
    }

    public function test_customer_session_is_not_an_admin_session(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($ticket->user, 'web')->get('/admin/tickets')->assertRedirect('/admin/login');
        $this->actingAs($ticket->user, 'web')->get("/admin/tickets/{$ticket->id}")->assertRedirect('/admin/login');
    }

    public function test_inactive_admin_is_denied(): void
    {
        $ticket = $this->ticket();
        $this->actingAs(Admin::factory()->inactive()->create(), 'admin');

        $this->get('/admin/tickets')->assertForbidden();
        $this->get("/admin/tickets/{$ticket->id}")->assertForbidden();
    }

    public function test_operations_and_super_admin_can_list_and_view(): void
    {
        $ticket = $this->ticket();

        foreach ([Admin::factory()->create(), Admin::factory()->superAdmin()->create()] as $admin) {
            $this->actingAs($admin, 'admin');

            $this->get('/admin/tickets')->assertOk()->assertSee('4821');
            $this->get("/admin/tickets/{$ticket->id}")->assertOk()->assertSee('4821');
        }
    }

    public function test_admin_denied_by_ticket_policy_gets_403(): void
    {
        Gate::policy(Ticket::class, DenyTicketViewPolicy::class);
        $ticket = $this->ticket();
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        $this->get('/admin/tickets')->assertForbidden();
        $this->get("/admin/tickets/{$ticket->id}")->assertForbidden();
    }

    // ---- Read-only / immutability -----------------------------------

    public function test_no_create_edit_or_delete_routes_or_capabilities(): void
    {
        $ticket = $this->ticket();
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        $this->assertSame(['index', 'view'], array_keys(TicketResource::getPages()));
        $this->get('/admin/tickets/create')->assertNotFound();
        $this->get("/admin/tickets/{$ticket->id}/edit")->assertNotFound();

        $this->assertTrue(TicketResource::canViewAny());
        $this->assertFalse(TicketResource::canCreate());
        $this->assertFalse(TicketResource::canEdit($ticket));
        $this->assertFalse(TicketResource::canDelete($ticket));
        $this->assertFalse(TicketResource::canDeleteAny());
        $this->assertFalse(TicketResource::canRestore($ticket));
        $this->assertFalse(TicketResource::canForceDelete($ticket));
        $this->assertFalse(TicketResource::canReplicate($ticket));

        $table = Livewire::test(ListTickets::class)->instance()->getTable();
        $this->assertSame(['view'], array_keys($table->getFlatActions()));
        $this->assertSame([], $table->getBulkActions());
        $this->assertSame([], $table->getHeaderActions());
    }

    /**
     * P11.7 §22/§44 — crafted Livewire requests against every immutable
     * ticket field. The row must be byte-for-byte unchanged.
     */
    public function test_crafted_livewire_requests_cannot_mutate_the_ticket(): void
    {
        $ticket = $this->ticket(10);
        $otherUser = User::factory()->create();
        $otherCompetition = Competition::factory()->create();
        $otherEntry = $this->allocate(tickets: 1);
        $otherOrder = Order::factory()->create();
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        $before = DB::table('tickets')->where('id', $ticket->id)->first();
        $ticketCount = DB::table('tickets')->count();

        $fields = [
            'ticket_number' => 99999,
            'user_id' => $otherUser->id,
            'competition_id' => $otherCompetition->id,
            'entry_id' => $otherEntry->id,
            'order_id' => $otherOrder->id,
        ];

        $attempts = [];

        foreach ($fields as $field => $value) {
            $attempts[] = fn () => Livewire::test(ViewTicket::class, ['record' => $ticket->id])->set("record.{$field}", $value);
            $attempts[] = fn () => Livewire::test(ViewTicket::class, ['record' => $ticket->id])->set("data.{$field}", $value)->call('save');
        }

        $attempts[] = fn () => Livewire::test(ViewTicket::class, ['record' => $ticket->id])->call('mountAction', 'edit')->call('callMountedAction');
        $attempts[] = fn () => Livewire::test(ViewTicket::class, ['record' => $ticket->id])->call('mountAction', 'delete')->call('callMountedAction');
        $attempts[] = fn () => Livewire::test(ListTickets::class)->call('mountTableAction', 'delete', (string) $ticket->id)->call('callMountedTableAction');
        $attempts[] = fn () => Livewire::test(ListTickets::class)->call('mountAction', 'create')->call('callMountedAction');
        $attempts[] = fn () => Livewire::test(ListTickets::class)->set('selectedTableRecords', [(string) $ticket->id])->call('mountTableBulkAction', 'delete')->call('callMountedTableBulkAction');

        foreach ($attempts as $attempt) {
            try {
                $attempt();
            } catch (Throwable) {
                // Refused outright — also a pass; the DB check is what matters.
            }
        }

        $this->assertEquals($before, DB::table('tickets')->where('id', $ticket->id)->first());
        $this->assertSame($ticketCount, DB::table('tickets')->count());
    }

    // ---- Content ------------------------------------------------------

    public function test_ticket_number_and_relationships_are_displayed_correctly(): void
    {
        $ticket = $this->ticket(4821);
        $this->actingAs(Admin::factory()->create(), 'admin');

        $this->get("/admin/tickets/{$ticket->id}")
            ->assertOk()
            ->assertSee('4821')
            ->assertDontSee('4,821')
            ->assertSee($ticket->competition->title)
            ->assertSee($ticket->user->name)
            ->assertSee($ticket->user->email)
            ->assertSee($ticket->order->reference)
            ->assertSee("#{$ticket->entry_id}")
            ->assertSee("/admin/competitions/{$ticket->competition_id}", false)
            ->assertSee("/admin/entries/{$ticket->entry_id}", false)
            ->assertSee("/admin/orders/{$ticket->order_id}", false);
    }

    public function test_winning_ticket_is_read_from_the_existing_draw_result(): void
    {
        $winner = $this->ticket(7);
        $loser = $this->allocate(tickets: 1, competition: $winner->competition, firstTicketNumber: 8)->tickets()->firstOrFail();
        DrawResult::factory()->create([
            'draw_id' => Draw::factory()->create(['competition_id' => $winner->competition_id])->id,
            'competition_id' => $winner->competition_id,
            'ticket_id' => $winner->id,
            'ticket_number' => 7,
        ]);
        $this->actingAs(Admin::factory()->create(), 'admin');

        $isWinning = fn (Ticket $ticket): bool => (bool) Livewire::test(ViewTicket::class, ['record' => $ticket->id])
            ->instance()->getSchema('infolist')->getComponent('is_winning_ticket', withHidden: true)?->getState();

        $this->assertTrue($isWinning($winner));
        $this->assertFalse($isWinning($loser));
    }

    public function test_sensitive_customer_fields_are_never_exposed(): void
    {
        $user = User::factory()->create(['remember_token' => 'REMEMBER-TOKEN-SENTINEL']);
        $ticket = $this->allocate(tickets: 1, user: $user)->tickets()->firstOrFail();
        $hash = DB::table('users')->where('id', $user->id)->value('password');
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        foreach (['/admin/tickets', "/admin/tickets/{$ticket->id}"] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertDontSee($hash, false)
                ->assertDontSee('REMEMBER-TOKEN-SENTINEL', false);
        }

        $loaded = TicketResource::getEloquentQuery()->findOrFail($ticket->id)->user;
        $this->assertSame(['id', 'name', 'email'], array_keys($loaded->getAttributes()));
    }

    // ---- Table ------------------------------------------------------

    public function test_competition_customer_order_and_allocated_date_filters(): void
    {
        $target = $this->ticket(1);
        $other = $this->ticket(2);
        DB::table('tickets')->where('id', $other->id)->update(['allocated_at' => '2026-01-05 10:00:00']);
        DB::table('tickets')->where('id', $target->id)->update(['allocated_at' => '2026-09-20 10:00:00']);
        $this->actingAs(Admin::factory()->create(), 'admin');

        $filters = [
            ['competition', ['competition_id' => $target->competition_id]],
            ['user', $target->user_id],
            ['order', ['order_id' => $target->order_id]],
            ['allocated_between', ['from' => '2026-09-01']],
        ];

        foreach ($filters as [$name, $value]) {
            Livewire::test(ListTickets::class)
                ->filterTable($name, $value)
                ->assertCanSeeTableRecords([$target])
                ->assertCanNotSeeTableRecords([$other]);
        }
    }

    public function test_ticket_number_search_is_exact_not_partial(): void
    {
        $exact = $this->ticket(12);
        $partial = $this->ticket(120);
        $this->actingAs(Admin::factory()->create(), 'admin');

        foreach (['12', '#12'] as $term) {
            Livewire::test(ListTickets::class)
                ->searchTable($term)
                ->assertCanSeeTableRecords([$exact])
                ->assertCanNotSeeTableRecords([$partial]);
        }
    }

    public function test_ticket_number_plus_competition_filter_identifies_one_ticket(): void
    {
        // Same number in two competitions — numbers are unique per competition.
        $first = $this->ticket(5);
        $second = $this->ticket(5);
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(ListTickets::class)
            ->searchTable('5')
            ->assertCanSeeTableRecords([$first, $second]);

        Livewire::test(ListTickets::class)
            ->filterTable('competition', ['competition_id' => $second->competition_id])
            ->searchTable('5')
            ->assertCanSeeTableRecords([$second])
            ->assertCanNotSeeTableRecords([$first]);
    }

    public function test_search_by_order_reference_customer_and_competition(): void
    {
        $user = User::factory()->create(['email' => 'ticket.holder@example.com']);
        $competition = Competition::factory()->create(['title' => 'Kilimanjaro Expedition']);
        $target = $this->allocate(tickets: 1, competition: $competition, user: $user, orderAttributes: ['reference' => 'RG-909090'])->tickets()->firstOrFail();
        $other = $this->ticket(3);
        $this->actingAs(Admin::factory()->create(), 'admin');

        foreach (['rg-9090', 'ticket.holder', 'Kilimanjaro'] as $term) {
            Livewire::test(ListTickets::class)
                ->searchTable($term)
                ->assertCanSeeTableRecords([$target])
                ->assertCanNotSeeTableRecords([$other]);
        }
    }

    public function test_pagination_is_server_side(): void
    {
        $this->allocate(tickets: 12);
        $this->actingAs(Admin::factory()->create(), 'admin');

        $records = Livewire::test(ListTickets::class)->set('tableRecordsPerPage', 5)->instance()->getTableRecords();

        $this->assertCount(5, $records);
        $this->assertSame(12, $records->total());
    }

    public function test_list_query_count_does_not_grow_with_rows(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');
        $counts = [];

        foreach ([1, 5, 25] as $target) {
            while (Ticket::query()->count() < $target) {
                // Spread across several entries/users/orders/competitions.
                $this->allocate(tickets: 1, firstTicketNumber: Ticket::query()->count() + 1);
            }

            foreach ([[], ['competition', ['competition_id' => Ticket::query()->value('competition_id')]]] as $filter) {
                DB::flushQueryLog();
                DB::enableQueryLog();
                $component = Livewire::test(ListTickets::class)->set('tableRecordsPerPage', 25);
                if ($filter !== []) {
                    $component->filterTable(...$filter);
                }
                DB::disableQueryLog();
                $counts[$target.($filter ? '-filtered' : '')] = count(DB::getQueryLog());
            }
        }

        $this->assertSame($counts['1'], $counts['5'], json_encode($counts));
        $this->assertSame($counts['1'], $counts['25'], json_encode($counts));
        $this->assertSame($counts['1-filtered'], $counts['25-filtered'], json_encode($counts));
    }

    public function test_navigation_group_and_no_draw_resource(): void
    {
        $this->assertSame('Tickets & Draws', TicketResource::getNavigationGroup());

        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');
        $this->get('/admin/draws')->assertNotFound();
    }
}

class DenyTicketViewPolicy extends TicketPolicy
{
    public function viewAny(Admin $admin): bool
    {
        return false;
    }

    public function view(Admin $admin, Ticket $ticket): bool
    {
        return false;
    }
}
