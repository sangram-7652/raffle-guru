<?php

namespace Tests\Feature\Admin;

use App\Enums\CompetitionStatus;
use App\Filament\Resources\Competitions\Pages\CreateCompetition;
use App\Filament\Resources\Competitions\Pages\EditCompetition;
use App\Filament\Resources\Competitions\Pages\ListCompetitions;
use App\Filament\Resources\Competitions\Pages\ViewCompetition;
use App\Models\Admin;
use App\Models\Competition;
use App\Models\Ticket;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * P11.5 — CompetitionResource: access, CRUD, validation, money/date
 * persistence, and the hard rule that status is never form-editable.
 */
class CompetitionResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validFormData(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Win a Porsche 911',
            'slug' => 'win-a-porsche-911',
            'short_description' => 'A brand new 911 Carrera.',
            'category' => 'Cars & Bikes',
            'entry_price' => '10.50',
            'max_entries' => 5000,
            'max_entries_per_user' => 50,
            'starts_at' => '2026-10-01 09:00:00',
            'ends_at' => '2026-10-15 21:00:00',
            'draw_at' => '2026-10-16 20:00:00',
            'is_featured' => true,
        ], $overrides);
    }

    // ---- Access -----------------------------------------------------

    public function test_guest_is_redirected_to_admin_login(): void
    {
        $this->get('/admin/competitions')->assertRedirect('/admin/login');
    }

    public function test_customer_session_is_not_an_admin_session(): void
    {
        $this->actingAs(User::factory()->create(), 'web')
            ->get('/admin/competitions')
            ->assertRedirect('/admin/login');
    }

    public function test_inactive_admin_is_denied(): void
    {
        $this->actingAs(Admin::factory()->inactive()->create(), 'admin')
            ->get('/admin/competitions')
            ->assertForbidden();
    }

    public function test_operations_can_list_competitions(): void
    {
        $competitions = Competition::factory()->count(3)->create();
        $this->actingAs(Admin::factory()->create(), 'admin');

        $this->get('/admin/competitions')->assertOk();

        Livewire::test(ListCompetitions::class)
            ->assertCanSeeTableRecords($competitions);
    }

    public function test_super_admin_can_list_competitions(): void
    {
        $competitions = Competition::factory()->count(2)->create();
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        $this->get('/admin/competitions')->assertOk();
        $this->get('/admin/competitions/create')->assertOk();
        $this->get("/admin/competitions/{$competitions[0]->id}")->assertOk();
        $this->get("/admin/competitions/{$competitions[0]->id}/edit")->assertOk();
    }

    public function test_table_search_and_status_filter_are_server_side(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');
        $porsche = Competition::factory()->create(['title' => 'Porsche Giveaway']);
        $cash = Competition::factory()->withStatus(CompetitionStatus::Closed)->create(['title' => 'Cash Drop']);

        Livewire::test(ListCompetitions::class)
            ->searchTable('Porsche')
            ->assertCanSeeTableRecords([$porsche])
            ->assertCanNotSeeTableRecords([$cash]);

        Livewire::test(ListCompetitions::class)
            ->filterTable('status', [CompetitionStatus::Closed->value])
            ->assertCanSeeTableRecords([$cash])
            ->assertCanNotSeeTableRecords([$porsche]);
    }

    public function test_list_page_query_count_does_not_grow_with_rows(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');
        Competition::factory()->count(3)->create();

        $countQueries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::test(ListCompetitions::class);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $few = $countQueries();
        Competition::factory()->count(20)->create();
        $many = $countQueries();

        $this->assertSame($few, $many, 'Competition list issues per-row queries (N+1).');
    }

    // ---- Create / update --------------------------------------------

    public function test_operations_can_create_a_competition(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(CreateCompetition::class)
            ->fillForm($this->validFormData([
                'description' => [['paragraph' => 'First paragraph.'], ['paragraph' => 'Second paragraph.']],
                'images' => [['url' => 'https://images.unsplash.com/photo-1']],
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $competition = Competition::query()->where('slug', 'win-a-porsche-911')->firstOrFail();

        $this->assertSame('Win a Porsche 911', $competition->title);
        $this->assertSame(CompetitionStatus::Draft, $competition->status);
        $this->assertSame('GBP', $competition->currency);
        $this->assertSame(['First paragraph.', 'Second paragraph.'], $competition->description);
        $this->assertSame(['https://images.unsplash.com/photo-1'], $competition->images);
        $this->assertSame(5000, $competition->max_entries);
        $this->assertSame(50, $competition->max_entries_per_user);
        $this->assertTrue($competition->is_featured);
    }

    public function test_operations_can_update_a_competition(): void
    {
        $competition = Competition::factory()->create(['title' => 'Old Title']);
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(EditCompetition::class, ['record' => $competition->getRouteKey()])
            ->fillForm(['title' => 'New Title', 'entry_price' => '2.99'])
            ->call('save')
            ->assertHasNoFormErrors();

        $competition->refresh();
        $this->assertSame('New Title', $competition->title);
        $this->assertSame(299, $competition->entry_price);
    }

    public function test_super_admin_can_create_and_update_competitions(): void
    {
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        Livewire::test(CreateCompetition::class)
            ->fillForm($this->validFormData())
            ->call('create')
            ->assertHasNoFormErrors();

        $competition = Competition::query()->where('slug', 'win-a-porsche-911')->firstOrFail();

        Livewire::test(EditCompetition::class, ['record' => $competition->getRouteKey()])
            ->fillForm(['short_description' => 'Updated by super admin'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Updated by super admin', $competition->fresh()->short_description);
    }

    public function test_required_fields_are_validated(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(CreateCompetition::class)
            ->fillForm([
                'title' => '',
                'slug' => '',
                'short_description' => '',
                'category' => '',
                'entry_price' => '',
                'max_entries' => '',
            ])
            ->call('create')
            ->assertHasFormErrors([
                'title' => 'required',
                'slug' => 'required',
                'short_description' => 'required',
                'category' => 'required',
                'entry_price' => 'required',
                'max_entries' => 'required',
            ]);

        $this->assertSame(0, Competition::query()->count());
    }

    public function test_numeric_bounds_are_validated(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(CreateCompetition::class)
            ->fillForm($this->validFormData([
                'entry_price' => '-1',
                'max_entries' => 0,
                'max_entries_per_user' => 0,
            ]))
            ->call('create')
            ->assertHasFormErrors(['entry_price', 'max_entries', 'max_entries_per_user']);
    }

    // ---- Slug -------------------------------------------------------

    public function test_slug_uniqueness_is_enforced_including_soft_deleted_rows(): void
    {
        Competition::factory()->create(['slug' => 'taken-slug']);
        Competition::factory()->create(['slug' => 'trashed-slug'])->delete();
        $this->actingAs(Admin::factory()->create(), 'admin');

        foreach (['taken-slug', 'trashed-slug'] as $slug) {
            Livewire::test(CreateCompetition::class)
                ->fillForm($this->validFormData(['slug' => $slug]))
                ->call('create')
                ->assertHasFormErrors(['slug' => 'unique']);
        }
    }

    public function test_slug_uniqueness_ignores_the_record_being_edited(): void
    {
        $competition = Competition::factory()->create(['slug' => 'my-slug']);
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(EditCompetition::class, ['record' => $competition->getRouteKey()])
            ->fillForm(['title' => 'Renamed'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('my-slug', $competition->fresh()->slug);
    }

    public function test_slug_format_is_validated(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');

        foreach (['Has Spaces', 'UPPER-case', 'trailing-', 'under_score'] as $slug) {
            Livewire::test(CreateCompetition::class)
                ->fillForm($this->validFormData(['slug' => $slug]))
                ->call('create')
                ->assertHasFormErrors(['slug' => 'regex']);
        }
    }

    public function test_slug_is_generated_from_title_on_create_only(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(CreateCompetition::class)
            ->set('data.title', 'Win A Rolex Submariner')
            ->assertSet('data.slug', 'win-a-rolex-submariner');

        $competition = Competition::factory()->create(['slug' => 'original-slug']);

        Livewire::test(EditCompetition::class, ['record' => $competition->getRouteKey()])
            ->set('data.title', 'Completely Different Title')
            ->assertSet('data.slug', 'original-slug');
    }

    // ---- Money ------------------------------------------------------

    public function test_money_persists_in_integer_minor_units(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');

        $cases = ['10.50' => 1050, '0.1' => 10, '0.99' => 99, '1999' => 199900, '0' => 0];

        foreach ($cases as $input => $pence) {
            $slug = 'money-'.$pence;

            Livewire::test(CreateCompetition::class)
                ->fillForm($this->validFormData(['slug' => $slug, 'entry_price' => (string) $input]))
                ->call('create')
                ->assertHasNoFormErrors();

            $raw = DB::table('competitions')->where('slug', $slug)->value('entry_price');
            $this->assertSame($pence, (int) $raw, "£{$input} should persist as {$pence}p");
        }
    }

    public function test_sub_penny_money_is_rejected_not_rounded(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(CreateCompetition::class)
            ->fillForm($this->validFormData(['entry_price' => '10.555']))
            ->call('create')
            ->assertHasFormErrors(['entry_price']);
    }

    public function test_edit_form_shows_pounds_and_pence(): void
    {
        $competition = Competition::factory()->create(['entry_price' => 1050]);
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(EditCompetition::class, ['record' => $competition->getRouteKey()])
            ->assertSchemaStateSet(['entry_price' => '10.50']);
    }

    public function test_crafted_currency_is_rejected_and_never_persisted(): void
    {
        $competition = Competition::factory()->create();
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(CreateCompetition::class)
            ->fillForm($this->validFormData())
            ->set('data.currency', 'USD')
            ->call('create')
            ->assertHasFormErrors(['currency']);

        $this->assertDatabaseMissing('competitions', ['slug' => 'win-a-porsche-911']);

        Livewire::test(EditCompetition::class, ['record' => $competition->getRouteKey()])
            ->set('data.currency', 'EUR')
            ->call('save')
            ->assertHasFormErrors(['currency']);

        $this->assertSame('GBP', $competition->fresh()->currency);
    }

    // ---- Dates ------------------------------------------------------

    public function test_dates_persist_in_the_application_timezone(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(CreateCompetition::class)
            ->fillForm($this->validFormData())
            ->call('create')
            ->assertHasNoFormErrors();

        $competition = Competition::query()->where('slug', 'win-a-porsche-911')->firstOrFail();
        $tz = config('app.timezone');

        $this->assertTrue($competition->starts_at->equalTo(Carbon::parse('2026-10-01 09:00:00', $tz)));
        $this->assertTrue($competition->ends_at->equalTo(Carbon::parse('2026-10-15 21:00:00', $tz)));
        $this->assertTrue($competition->draw_at->equalTo(Carbon::parse('2026-10-16 20:00:00', $tz)));
    }

    public function test_all_dates_are_optional(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(CreateCompetition::class)
            ->fillForm($this->validFormData(['starts_at' => null, 'ends_at' => null, 'draw_at' => null]))
            ->call('create')
            ->assertHasNoFormErrors();

        $competition = Competition::query()->where('slug', 'win-a-porsche-911')->firstOrFail();
        $this->assertNull($competition->starts_at);
        $this->assertNull($competition->ends_at);
        $this->assertNull($competition->draw_at);
    }

    public function test_date_ordering_is_validated(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(CreateCompetition::class)
            ->fillForm($this->validFormData(['ends_at' => '2026-09-30 09:00:00']))
            ->call('create')
            ->assertHasFormErrors(['ends_at']);

        Livewire::test(CreateCompetition::class)
            ->fillForm($this->validFormData(['ends_at' => '2026-10-01 09:00:00']))
            ->call('create')
            ->assertHasFormErrors(['ends_at']);

        Livewire::test(CreateCompetition::class)
            ->fillForm($this->validFormData(['draw_at' => '2026-10-15 20:59:00']))
            ->call('create')
            ->assertHasFormErrors(['draw_at']);

        // With no ends_at, draw_at is checked against starts_at instead.
        Livewire::test(CreateCompetition::class)
            ->fillForm($this->validFormData(['ends_at' => null, 'draw_at' => '2026-09-01 00:00:00']))
            ->call('create')
            ->assertHasFormErrors(['draw_at']);
    }

    // ---- Capacity ---------------------------------------------------

    public function test_max_entries_per_user_cannot_exceed_max_entries(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(CreateCompetition::class)
            ->fillForm($this->validFormData(['max_entries' => 10, 'max_entries_per_user' => 11]))
            ->call('create')
            ->assertHasFormErrors(['max_entries_per_user']);
    }

    public function test_max_entries_cannot_drop_below_allocated_tickets(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create(['max_entries' => 100]);
        Ticket::factory()->count(5)->create(['competition_id' => $competition->id]);
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(EditCompetition::class, ['record' => $competition->getRouteKey()])
            ->fillForm(['max_entries' => 4])
            ->call('save')
            ->assertHasFormErrors(['max_entries']);

        Livewire::test(EditCompetition::class, ['record' => $competition->getRouteKey()])
            ->fillForm(['max_entries' => 5])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(5, $competition->fresh()->max_entries);
    }

    // ---- Skill question ---------------------------------------------

    public function test_skill_question_persists_in_the_existing_shape(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(CreateCompetition::class)
            ->fillForm($this->validFormData([
                'skill_question' => [
                    'prompt' => 'What colour is the sky?',
                    'options' => [['option' => 'Green'], ['option' => 'Blue'], ['option' => 'Red']],
                ],
            ]))
            ->fillForm(['skill_question.answer_index' => '1'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame([
            'prompt' => 'What colour is the sky?',
            'options' => ['Green', 'Blue', 'Red'],
            'answer_index' => 1,
        ], Competition::query()->where('slug', 'win-a-porsche-911')->firstOrFail()->skill_question);
    }

    public function test_blank_skill_question_is_stored_as_null_for_free_entry(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(CreateCompetition::class)
            ->fillForm($this->validFormData())
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertNull(DB::table('competitions')->where('slug', 'win-a-porsche-911')->value('skill_question'));
    }

    public function test_incomplete_skill_question_is_rejected(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');

        // Prompt with too few options and no answer.
        Livewire::test(CreateCompetition::class)
            ->fillForm($this->validFormData(['skill_question' => ['prompt' => 'Question?', 'options' => [['option' => 'Only one']]]]))
            ->call('create')
            ->assertHasFormErrors(['skill_question.options', 'skill_question.answer_index']);

        // Options with no prompt.
        Livewire::test(CreateCompetition::class)
            ->fillForm($this->validFormData(['skill_question' => ['options' => [['option' => 'A'], ['option' => 'B']]]]))
            ->call('create')
            ->assertHasFormErrors(['skill_question.prompt']);

        // Answer index out of range.
        Livewire::test(CreateCompetition::class)
            ->fillForm($this->validFormData(['skill_question' => ['prompt' => 'Q?', 'options' => [['option' => 'A'], ['option' => 'B']]]]))
            ->fillForm(['skill_question.answer_index' => '7'])
            ->call('create')
            ->assertHasFormErrors(['skill_question.answer_index']);

        $this->assertSame(0, Competition::query()->count());
    }

    public function test_public_api_still_never_exposes_answer_index_for_admin_created_competitions(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Scheduled)->create();
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(EditCompetition::class, ['record' => $competition->getRouteKey()])
            ->fillForm(['skill_question' => ['prompt' => 'Secret?', 'options' => [['option' => 'A'], ['option' => 'B']]]])
            ->fillForm(['skill_question.answer_index' => '1'])
            ->call('save')
            ->assertHasNoFormErrors();

        // Make it publicly visible without touching status via Filament.
        DB::table('competitions')->where('id', $competition->id)->update(['status' => CompetitionStatus::Active->value]);

        $this->getJson("/api/v1/competitions/{$competition->slug}")
            ->assertOk()
            ->assertJsonPath('data.skill_question.prompt', 'Secret?')
            ->assertJsonPath('data.skill_question.options', ['A', 'B'])
            ->assertJsonMissingPath('data.skill_question.answer_index');
    }

    // ---- Status is never form-editable (P11.5 §11 / §31) ------------

    public function test_status_is_not_a_form_field(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create();
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(CreateCompetition::class)->assertFormFieldDoesNotExist('status');
        Livewire::test(EditCompetition::class, ['record' => $competition->getRouteKey()])
            ->assertFormFieldDoesNotExist('status');
    }

    public function test_crafted_status_in_edit_payload_is_ignored(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Active)->create();
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(EditCompetition::class, ['record' => $competition->getRouteKey()])
            ->set('data.status', CompetitionStatus::Completed->value)
            ->fillForm(['title' => 'Renamed But Still Active'])
            ->call('save')
            ->assertHasNoFormErrors();

        $competition->refresh();
        $this->assertSame('Renamed But Still Active', $competition->title);
        $this->assertSame(CompetitionStatus::Active, $competition->status);
        $this->assertSame(0, $competition->lifecycleEvents()->count());
    }

    public function test_crafted_status_in_create_payload_is_ignored(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(CreateCompetition::class)
            ->fillForm($this->validFormData())
            ->set('data.status', CompetitionStatus::Active->value)
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(
            CompetitionStatus::Draft,
            Competition::query()->where('slug', 'win-a-porsche-911')->firstOrFail()->status,
        );
    }

    /**
     * Mechanical proof, not just behavioural: no code under app/Filament
     * assigns `status` directly. Comments are stripped via the tokenizer
     * so docblocks explaining the rule can't cause false positives.
     */
    public function test_no_direct_status_mutation_exists_in_the_filament_tree(): void
    {
        $patterns = [
            '/[\'"]status[\'"]\s*=>/',            // ['status' => ...] in update()/create()/fill()
            '/\[\s*[\'"]status[\'"]\s*\]\s*=[^=]/', // $data['status'] = ...
            '/->status\s*=[^=]/',                  // $model->status = ...
            '/setAttribute\(\s*[\'"]status/',
            '/forceFill\(/',
            '/DB::table\(\s*[\'"]competitions[\'"]\s*\)[^;]*->(update|insert|upsert)\(/s',
        ];
        $matches = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path('Filament')));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $code = collect(token_get_all(file_get_contents($file->getPathname())))
                ->reject(fn ($token) => is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true))
                ->map(fn ($token) => is_array($token) ? $token[1] : $token)
                ->implode('');

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $code)) {
                    $matches[] = str_replace(app_path(), 'app', $file->getPathname())." ({$pattern})";
                }
            }
        }

        $this->assertSame([], $matches, 'Direct status mutation found in app/Filament.');
    }

    // ---- Deletion ---------------------------------------------------

    public function test_operations_cannot_delete_competitions(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Draft)->create();
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(EditCompetition::class, ['record' => $competition->getRouteKey()])
            ->assertActionHidden(DeleteAction::class)
            // A crafted request straight at the Livewire endpoints, as a
            // browser devtools user could send — not the UI button.
            ->call('mountAction', 'delete')
            ->call('callMountedAction');

        $this->assertNotSoftDeleted($competition);
    }

    public function test_super_admin_can_soft_delete_and_restore_a_pre_launch_competition(): void
    {
        $competition = Competition::factory()->withStatus(CompetitionStatus::Draft)->create();
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        // Same raw Livewire calls the refusal tests below use — proves
        // that path really deletes when allowed, so their "not deleted"
        // assertions aren't passing vacuously.
        Livewire::test(EditCompetition::class, ['record' => $competition->getRouteKey()])
            ->call('mountAction', 'delete')
            ->call('callMountedAction');

        $this->assertSoftDeleted($competition);

        Livewire::test(EditCompetition::class, ['record' => $competition->getRouteKey()])
            ->callAction(RestoreAction::class);

        $this->assertNotSoftDeleted($competition);
    }

    public function test_live_or_finished_competitions_cannot_be_deleted_even_by_super_admin(): void
    {
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        foreach ([CompetitionStatus::Active, CompetitionStatus::Closed, CompetitionStatus::Drawn] as $status) {
            $competition = Competition::factory()->withStatus($status)->create();

            Livewire::test(EditCompetition::class, ['record' => $competition->getRouteKey()])
                ->assertActionHidden(DeleteAction::class)
                ->call('mountAction', 'delete')
                ->call('callMountedAction');

            $this->assertNotSoftDeleted($competition);
        }
    }

    public function test_competition_table_has_no_bulk_actions(): void
    {
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        $table = Livewire::test(ListCompetitions::class)->instance()->getTable();

        $this->assertSame([], $table->getBulkActions());
        $this->assertSame([], $table->getToolbarActions());
    }

    public function test_view_page_renders_skill_answer_for_admins_only_in_panel(): void
    {
        $competition = Competition::factory()->create();
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(ViewCompetition::class, ['record' => $competition->getRouteKey()])
            ->assertOk()
            ->assertSee('Option 2: Blue');
    }
}
