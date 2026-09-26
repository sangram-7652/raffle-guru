<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\Entries\EntryResource;
use App\Filament\Resources\Entries\Pages\ListEntries;
use App\Filament\Resources\Entries\Pages\ViewEntry;
use App\Filament\Resources\Entries\RelationManagers\TicketsRelationManager;
use App\Models\Admin;
use App\Models\Competition;
use App\Models\Entry;
use App\Models\User;
use App\Policies\EntryPolicy;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Feature\Admin\Concerns\BuildsAllocations;
use Tests\TestCase;
use Throwable;

/**
 * P11.7 — EntryResource: read-only inspection of P7 entries.
 */
class EntryResourceTest extends TestCase
{
    use BuildsAllocations, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    // ---- Access -----------------------------------------------------

    public function test_guest_is_redirected_to_admin_login(): void
    {
        $entry = $this->allocate();

        $this->get('/admin/entries')->assertRedirect('/admin/login');
        $this->get("/admin/entries/{$entry->id}")->assertRedirect('/admin/login');
    }

    public function test_customer_session_is_not_an_admin_session(): void
    {
        $entry = $this->allocate();

        $this->actingAs($entry->user, 'web')->get('/admin/entries')->assertRedirect('/admin/login');
        $this->actingAs($entry->user, 'web')->get("/admin/entries/{$entry->id}")->assertRedirect('/admin/login');
    }

    public function test_inactive_admin_is_denied(): void
    {
        $entry = $this->allocate();
        $this->actingAs(Admin::factory()->inactive()->create(), 'admin');

        $this->get('/admin/entries')->assertForbidden();
        $this->get("/admin/entries/{$entry->id}")->assertForbidden();
    }

    public function test_operations_and_super_admin_can_list_and_view(): void
    {
        $entry = $this->allocate();

        foreach ([Admin::factory()->create(), Admin::factory()->superAdmin()->create()] as $admin) {
            $this->actingAs($admin, 'admin');

            $this->get('/admin/entries')->assertOk()->assertSee($entry->competition->title);
            $this->get("/admin/entries/{$entry->id}")->assertOk()->assertSee($entry->order->reference);
        }
    }

    public function test_admin_denied_by_entry_policy_gets_403(): void
    {
        Gate::policy(Entry::class, DenyEntryViewPolicy::class);
        $entry = $this->allocate();
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        $this->get('/admin/entries')->assertForbidden();
        $this->get("/admin/entries/{$entry->id}")->assertForbidden();
    }

    // ---- Read-only enforcement --------------------------------------

    public function test_no_create_edit_or_delete_routes_or_capabilities(): void
    {
        $entry = $this->allocate();
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        $this->assertSame(['index', 'view'], array_keys(EntryResource::getPages()));
        $this->get('/admin/entries/create')->assertNotFound();
        $this->get("/admin/entries/{$entry->id}/edit")->assertNotFound();

        $this->assertTrue(EntryResource::canViewAny());
        $this->assertFalse(EntryResource::canCreate());
        $this->assertFalse(EntryResource::canEdit($entry));
        $this->assertFalse(EntryResource::canDelete($entry));
        $this->assertFalse(EntryResource::canDeleteAny());
        $this->assertFalse(EntryResource::canRestore($entry));
        $this->assertFalse(EntryResource::canForceDelete($entry));
        $this->assertFalse(EntryResource::canReplicate($entry));
    }

    public function test_table_has_only_view_and_relation_manager_has_no_actions(): void
    {
        $entry = $this->allocate();
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        $table = Livewire::test(ListEntries::class)->instance()->getTable();
        $this->assertSame(['view'], array_keys($table->getFlatActions()));
        $this->assertSame([], $table->getBulkActions());
        $this->assertSame([], $table->getHeaderActions());

        $manager = Livewire::test(TicketsRelationManager::class, ['ownerRecord' => $entry, 'pageClass' => ViewEntry::class]);
        $this->assertTrue($manager->instance()->isReadOnly());
        $rmTable = $manager->instance()->getTable();
        $this->assertSame([], $rmTable->getFlatActions());
        $this->assertSame([], $rmTable->getHeaderActions());
        $this->assertSame([], $rmTable->getBulkActions());
    }

    /**
     * P11.7 §22/§44 — crafted Livewire requests must not change entry
     * ownership, competition, order, or quantity.
     */
    public function test_crafted_livewire_requests_cannot_mutate_the_entry(): void
    {
        $entry = $this->allocate();
        $otherUser = User::factory()->create();
        $otherCompetition = Competition::factory()->create();
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        $before = DB::table('entries')->where('id', $entry->id)->first();
        $ticketsBefore = DB::table('tickets')->where('entry_id', $entry->id)->orderBy('id')->get();

        $attempts = [
            fn () => Livewire::test(ViewEntry::class, ['record' => $entry->id])->set('record.user_id', $otherUser->id),
            fn () => Livewire::test(ViewEntry::class, ['record' => $entry->id])->set('record.competition_id', $otherCompetition->id),
            fn () => Livewire::test(ViewEntry::class, ['record' => $entry->id])->set('record.quantity', 999),
            fn () => Livewire::test(ViewEntry::class, ['record' => $entry->id])->set('data.user_id', $otherUser->id)->call('save'),
            fn () => Livewire::test(ViewEntry::class, ['record' => $entry->id])->call('mountAction', 'delete')->call('callMountedAction'),
            fn () => Livewire::test(ListEntries::class)->call('mountTableAction', 'delete', (string) $entry->id)->call('callMountedTableAction'),
            fn () => Livewire::test(ListEntries::class)->set('selectedTableRecords', [(string) $entry->id])->call('mountTableBulkAction', 'delete')->call('callMountedTableBulkAction'),
            fn () => Livewire::test(TicketsRelationManager::class, ['ownerRecord' => $entry, 'pageClass' => ViewEntry::class])
                ->call('mountTableAction', 'create')->call('callMountedTableAction'),
            fn () => Livewire::test(TicketsRelationManager::class, ['ownerRecord' => $entry, 'pageClass' => ViewEntry::class])
                ->call('mountTableAction', 'delete', (string) $ticketsBefore[0]->id)->call('callMountedTableAction'),
        ];

        foreach ($attempts as $attempt) {
            try {
                $attempt();
            } catch (Throwable) {
                // Refused outright — also a pass; the DB check is what matters.
            }
        }

        $this->assertEquals($before, DB::table('entries')->where('id', $entry->id)->first());
        $this->assertEquals($ticketsBefore, DB::table('tickets')->where('entry_id', $entry->id)->orderBy('id')->get());
    }

    // ---- Content ------------------------------------------------------

    public function test_view_shows_who_what_and_through_which_order(): void
    {
        $entry = $this->allocate(tickets: 3, firstTicketNumber: 41);
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(ViewEntry::class, ['record' => $entry->id])
            ->assertOk()
            ->assertSee("#{$entry->id}")
            ->assertSee($entry->competition->title)
            ->assertSee($entry->user->name)
            ->assertSee($entry->user->email)
            ->assertSee($entry->order->reference)
            ->assertSee('£3.50')
            ->assertSee('£10.50');

        Livewire::test(TicketsRelationManager::class, ['ownerRecord' => $entry, 'pageClass' => ViewEntry::class])
            ->assertCanSeeTableRecords($entry->tickets)
            ->assertSee('41')
            ->assertSee('43')
            ->assertSee('/admin/tickets/'.$entry->tickets()->orderBy('id')->value('id'), false);
    }

    public function test_view_links_to_competition_and_order(): void
    {
        $entry = $this->allocate();
        $this->actingAs(Admin::factory()->create(), 'admin');

        $this->get("/admin/entries/{$entry->id}")
            ->assertSee("/admin/competitions/{$entry->competition_id}", false)
            ->assertSee("/admin/orders/{$entry->order_id}", false);
    }

    public function test_relation_manager_only_lists_this_entrys_tickets(): void
    {
        $entry = $this->allocate(tickets: 2);
        $other = $this->allocate(tickets: 2, competition: $entry->competition, firstTicketNumber: 3);
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(TicketsRelationManager::class, ['ownerRecord' => $entry, 'pageClass' => ViewEntry::class])
            ->assertCanSeeTableRecords($entry->tickets)
            ->assertCanNotSeeTableRecords($other->tickets);
    }

    public function test_sensitive_customer_fields_are_never_exposed(): void
    {
        $user = User::factory()->create(['remember_token' => 'REMEMBER-TOKEN-SENTINEL']);
        $entry = $this->allocate(user: $user);
        $hash = DB::table('users')->where('id', $user->id)->value('password');
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        foreach (['/admin/entries', "/admin/entries/{$entry->id}"] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertDontSee($hash, false)
                ->assertDontSee('REMEMBER-TOKEN-SENTINEL', false);
        }

        $loaded = EntryResource::getEloquentQuery()->findOrFail($entry->id)->user;
        $this->assertSame(['id', 'name', 'email'], array_keys($loaded->getAttributes()));
    }

    // ---- Table: filters, search, pagination, queries ----------------

    public function test_competition_customer_order_and_date_filters(): void
    {
        $target = $this->allocate();
        $other = $this->allocate();
        DB::table('entries')->where('id', $other->id)->update(['created_at' => '2026-01-05 10:00:00']);
        DB::table('entries')->where('id', $target->id)->update(['created_at' => '2026-09-20 10:00:00']);
        $this->actingAs(Admin::factory()->create(), 'admin');

        $filters = [
            ['competition', ['competition_id' => $target->competition_id]],
            ['user', $target->user_id],
            ['order', ['order_id' => $target->order_id]],
            ['created_between', ['from' => '2026-09-01']],
        ];

        foreach ($filters as [$name, $value]) {
            Livewire::test(ListEntries::class)
                ->filterTable($name, $value)
                ->assertCanSeeTableRecords([$target])
                ->assertCanNotSeeTableRecords([$other]);
        }
    }

    public function test_search_by_entry_id_order_reference_customer_and_competition(): void
    {
        $user = User::factory()->create(['email' => 'searchable.entrant@example.com']);
        $competition = Competition::factory()->create(['title' => 'Zanzibar Island Getaway']);
        $target = $this->allocate(competition: $competition, user: $user, orderAttributes: ['reference' => 'RG-808080']);
        $other = $this->allocate();
        $this->actingAs(Admin::factory()->create(), 'admin');

        foreach (["#{$target->id}", 'rg-8080', 'searchable.entrant', 'Zanzibar'] as $term) {
            Livewire::test(ListEntries::class)
                ->searchTable($term)
                ->assertCanSeeTableRecords([$target])
                ->assertCanNotSeeTableRecords([$other]);
        }
    }

    public function test_pagination_is_server_side(): void
    {
        foreach (range(1, 12) as $i) {
            $this->allocate(tickets: 1);
        }
        $this->actingAs(Admin::factory()->create(), 'admin');

        $records = Livewire::test(ListEntries::class)->set('tableRecordsPerPage', 5)->instance()->getTableRecords();

        $this->assertCount(5, $records);
        $this->assertSame(12, $records->total());
    }

    public function test_list_query_count_does_not_grow_with_rows(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');
        $this->allocate(tickets: 1);

        $counts = [];

        foreach ([1, 5, 20] as $target) {
            while (Entry::query()->count() < $target) {
                $this->allocate(tickets: 2);
            }

            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::test(ListEntries::class)->set('tableRecordsPerPage', 25);
            DB::disableQueryLog();
            $counts[$target] = count(DB::getQueryLog());
        }

        $this->assertCount(1, array_unique($counts), 'Entry list query count grows with rows: '.json_encode($counts));
    }

    public function test_view_page_query_count_does_not_grow_with_tickets(): void
    {
        $small = $this->allocate(tickets: 1);
        $large = $this->allocate(tickets: 30);
        $this->actingAs(Admin::factory()->create(), 'admin');

        $count = function (Entry $entry): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::test(ViewEntry::class, ['record' => $entry->id]);
            Livewire::test(TicketsRelationManager::class, ['ownerRecord' => $entry, 'pageClass' => ViewEntry::class]);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $this->assertSame($count($small), $count($large));
    }

    public function test_navigation_group(): void
    {
        $this->assertSame('Tickets & Draws', EntryResource::getNavigationGroup());
    }
}

class DenyEntryViewPolicy extends EntryPolicy
{
    public function viewAny(Admin $admin): bool
    {
        return false;
    }

    public function view(Admin $admin, Entry $entry): bool
    {
        return false;
    }
}
