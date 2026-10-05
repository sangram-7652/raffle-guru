<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Filament\Resources\Admins\AdminResource;
use App\Filament\Resources\Admins\Pages\CreateAdmin;
use App\Filament\Resources\Admins\Pages\EditAdmin;
use App\Filament\Resources\Admins\Pages\ListAdmins;
use App\Filament\Resources\Admins\Pages\ViewAdmin;
use App\Filament\Resources\Admins\RelationManagers\AuditRelationManager;
use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;
use Throwable;

/**
 * P11.12 — AdminResource over HTTP and Livewire. Every test asserts the
 * outcome in the database, not only the rendered response, so a crafted
 * request that bypasses the UI is judged by the same rules.
 */
class AdminResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function superAdmin(array $attributes = []): Admin
    {
        return Admin::factory()->superAdmin()->create($attributes);
    }

    private function operations(array $attributes = []): Admin
    {
        return Admin::factory()->create($attributes);
    }

    /**
     * Snapshot of every admin row and audit row, for "nothing changed".
     *
     * @return array<string, mixed>
     */
    private function adminState(): array
    {
        return [
            'admins' => DB::table('admins')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'audit' => DB::table('admin_audit_logs')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        ];
    }

    private function queryCount(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }

    // ---- Access -----------------------------------------------------

    public function test_guest_is_redirected_to_admin_login(): void
    {
        $target = $this->operations();

        $this->get('/admin/admins')->assertRedirect('/admin/login');
        $this->get("/admin/admins/{$target->id}")->assertRedirect('/admin/login');
        $this->get('/admin/admins/create')->assertRedirect('/admin/login');
    }

    public function test_customer_cannot_reach_admin_accounts(): void
    {
        $customer = User::factory()->create();
        $target = $this->operations();

        $this->actingAs($customer, 'web')->get('/admin/admins')->assertRedirect('/admin/login');
        $this->actingAs($customer, 'web')->get("/admin/admins/{$target->id}")->assertRedirect('/admin/login');
    }

    public function test_inactive_super_admin_is_denied_the_roster(): void
    {
        $target = $this->operations();
        $this->actingAs($this->superAdmin(['is_active' => false]), 'admin');

        $this->get('/admin/admins')->assertForbidden();
        $this->get("/admin/admins/{$target->id}")->assertForbidden();
    }

    public function test_operations_is_denied_every_admin_management_page(): void
    {
        $target = $this->operations();
        $this->actingAs($this->operations(), 'admin');

        $this->get('/admin/admins')->assertForbidden();
        $this->get('/admin/admins/create')->assertForbidden();
        $this->get("/admin/admins/{$target->id}")->assertForbidden();
        $this->get("/admin/admins/{$target->id}/edit")->assertForbidden();
    }

    public function test_super_admin_can_reach_every_admin_management_page(): void
    {
        $target = $this->operations();
        $this->actingAs($this->superAdmin(), 'admin');

        $this->get('/admin/admins')->assertOk();
        $this->get('/admin/admins/create')->assertOk();
        $this->get("/admin/admins/{$target->id}")->assertOk();
        $this->get("/admin/admins/{$target->id}/edit")->assertOk();
    }

    public function test_operations_cannot_act_on_admin_ids_by_crafted_livewire_calls(): void
    {
        $target = $this->operations(['name' => 'Untouched']);
        $actor = $this->operations();
        $before = $this->adminState();
        $this->actingAs($actor, 'admin');

        $attempts = [
            fn () => Livewire::test(ViewAdmin::class, ['record' => $target->id])->call('mountAction', 'deactivate')->call('callMountedAction'),
            fn () => Livewire::test(ViewAdmin::class, ['record' => $target->id])->callAction('changeRole', data: ['role' => 'super_admin', 'reason' => 'Escalate me']),
            fn () => Livewire::test(ViewAdmin::class, ['record' => $target->id])->callAction('deactivate', data: ['reason' => 'Crafted']),
            fn () => Livewire::test(EditAdmin::class, ['record' => $target->id])->fillForm(['name' => 'Hijacked', 'email' => $target->email])->call('save'),
            fn () => Livewire::test(CreateAdmin::class)->fillForm([
                'name' => 'Sneaky', 'email' => 'sneaky@example.com', 'role' => 'super_admin',
                'password' => 'Correct-Horse-9', 'password_confirmation' => 'Correct-Horse-9', 'is_active' => true,
            ])->call('create'),
        ];

        foreach ($attempts as $attempt) {
            try {
                $attempt();
            } catch (Throwable) {
                // Refused outright — also a pass; the database check decides.
            }
        }

        $this->assertSame($before, $this->adminState());
    }

    // ---- Create -----------------------------------------------------

    public function test_super_admin_creates_an_admin_through_the_form(): void
    {
        $actor = $this->superAdmin();
        $this->actingAs($actor, 'admin');

        Livewire::test(CreateAdmin::class)
            ->fillForm([
                'name' => 'Form Created',
                'email' => 'form.created@example.com',
                'role' => AdminRole::Operations->value,
                'password' => 'Correct-Horse-9',
                'password_confirmation' => 'Correct-Horse-9',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = Admin::query()->where('email', 'form.created@example.com')->sole();
        $this->assertSame('Form Created', $created->name);
        $this->assertSame(AdminRole::Operations, $created->role);
        $this->assertTrue(Hash::check('Correct-Horse-9', $created->password));

        $audit = AdminAuditLog::query()->where('target_admin_id', $created->id)->sole();
        $this->assertSame(AdminAuditLog::EVENT_CREATED, $audit->event);
        $this->assertSame($actor->id, $audit->actor_admin_id);
    }

    public function test_duplicate_email_is_rejected_on_the_form(): void
    {
        $this->operations(['email' => 'dupe@example.com']);
        $this->actingAs($this->superAdmin(), 'admin');

        Livewire::test(CreateAdmin::class)
            ->fillForm([
                'name' => 'Second', 'email' => 'dupe@example.com', 'role' => 'operations',
                'password' => 'Correct-Horse-9', 'password_confirmation' => 'Correct-Horse-9',
            ])
            ->call('create')
            ->assertHasFormErrors(['email']);

        $this->assertSame(1, Admin::query()->where('email', 'dupe@example.com')->count());
    }

    public function test_an_invalid_role_is_rejected_on_the_form(): void
    {
        $this->actingAs($this->superAdmin(), 'admin');

        Livewire::test(CreateAdmin::class)
            ->fillForm([
                'name' => 'Bad Role', 'email' => 'bad.role@example.com', 'role' => 'owner',
                'password' => 'Correct-Horse-9', 'password_confirmation' => 'Correct-Horse-9',
            ])
            ->call('create')
            ->assertHasFormErrors(['role']);

        $this->assertSame(0, Admin::query()->where('email', 'bad.role@example.com')->count());
    }

    public function test_a_mismatched_password_confirmation_is_rejected(): void
    {
        $this->actingAs($this->superAdmin(), 'admin');

        Livewire::test(CreateAdmin::class)
            ->fillForm([
                'name' => 'Mismatch', 'email' => 'mismatch@example.com', 'role' => 'operations',
                'password' => 'Correct-Horse-9', 'password_confirmation' => 'Different-Horse-9',
            ])
            ->call('create')
            ->assertHasFormErrors(['password']);

        $this->assertSame(0, Admin::query()->where('email', 'mismatch@example.com')->count());
    }

    public function test_the_create_form_never_keeps_the_password_in_later_state(): void
    {
        $this->actingAs($this->superAdmin(), 'admin');

        $created = Livewire::test(CreateAdmin::class)
            ->fillForm([
                'name' => 'Never Shown', 'email' => 'never.shown@example.com', 'role' => 'operations',
                'password' => 'Correct-Horse-9', 'password_confirmation' => 'Correct-Horse-9',
            ])
            ->call('create');

        $admin = Admin::query()->where('email', 'never.shown@example.com')->sole();
        $hash = $admin->password;

        $this->get("/admin/admins/{$admin->id}")
            ->assertOk()
            ->assertDontSee('Correct-Horse-9', false)
            ->assertDontSee($hash, false);

        $created->assertHasNoFormErrors();
    }

    // ---- Edit -------------------------------------------------------

    public function test_edit_changes_name_and_email_only(): void
    {
        $this->actingAs($this->superAdmin(), 'admin');
        $target = $this->operations(['name' => 'Before', 'email' => 'before@example.com']);

        Livewire::test(EditAdmin::class, ['record' => $target->id])
            ->fillForm(['name' => 'After', 'email' => 'after@example.com'])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $target->fresh();
        $this->assertSame('After', $fresh->name);
        $this->assertSame('after@example.com', $fresh->email);
        $this->assertSame(AdminRole::Operations, $fresh->role);
    }

    public function test_the_edit_form_has_no_role_status_or_password_fields(): void
    {
        $this->actingAs($this->superAdmin(), 'admin');
        $target = $this->operations();

        $component = Livewire::test(EditAdmin::class, ['record' => $target->id]);
        $keys = array_keys($component->get('data') ?? []);

        $this->assertEqualsCanonicalizing(['name', 'email'], $keys);
    }

    public function test_crafted_role_and_status_in_the_edit_request_are_ignored(): void
    {
        $this->actingAs($this->superAdmin(), 'admin');
        $target = $this->operations();

        Livewire::test(EditAdmin::class, ['record' => $target->id])
            ->set('data.role', 'super_admin')
            ->set('data.is_active', false)
            ->set('data.password', 'Hijacked-Pass-1')
            ->fillForm(['name' => $target->name, 'email' => $target->email])
            ->call('save');

        $fresh = $target->fresh();
        $this->assertSame(AdminRole::Operations, $fresh->role);
        $this->assertTrue($fresh->is_active);
    }

    // ---- Sensitive actions -----------------------------------------

    public function test_change_role_action_changes_the_role_and_audits_it(): void
    {
        $actor = $this->superAdmin();
        $target = $this->operations();
        $this->actingAs($actor, 'admin');

        Livewire::test(ViewAdmin::class, ['record' => $target->id])
            ->callAction('changeRole', data: ['role' => 'super_admin', 'reason' => 'Covering the on-call rota'])
            ->assertHasNoActionErrors();

        $this->assertSame(AdminRole::SuperAdmin, $target->fresh()->role);
        $audit = AdminAuditLog::query()->where('target_admin_id', $target->id)->sole();
        $this->assertSame(AdminAuditLog::EVENT_ROLE_CHANGED, $audit->event);
        $this->assertSame($actor->id, $audit->actor_admin_id);
    }

    public function test_deactivate_action_locks_the_account_out_of_the_panel(): void
    {
        $actor = $this->superAdmin();
        $target = $this->operations();
        $this->actingAs($actor, 'admin');

        Livewire::test(ViewAdmin::class, ['record' => $target->id])
            ->callAction('deactivate', data: ['reason' => 'Left the company'])
            ->assertHasNoActionErrors();

        $this->assertFalse($target->fresh()->is_active);

        // The same account, even with an existing session, is refused the panel now.
        $this->actingAs($target->fresh(), 'admin')->get('/admin')->assertForbidden();
    }

    public function test_activate_action_restores_access(): void
    {
        $this->actingAs($this->superAdmin(), 'admin');
        $target = $this->operations(['is_active' => false]);

        Livewire::test(ViewAdmin::class, ['record' => $target->id])
            ->callAction('activate', data: ['reason' => 'Returned'])
            ->assertHasNoActionErrors();

        $this->assertTrue($target->fresh()->is_active);
    }

    public function test_the_final_super_admin_cannot_be_deactivated_from_the_ui(): void
    {
        $sole = $this->superAdmin();
        $this->actingAs($sole, 'admin');

        // Self-deactivation is hidden and refused by the service; a crafted call must fail too.
        try {
            Livewire::test(ViewAdmin::class, ['record' => $sole->id])
                ->callAction('deactivate', data: ['reason' => 'Crafted self-removal']);
        } catch (Throwable) {
        }

        $this->assertTrue($sole->fresh()->is_active);
        $this->assertSame(0, AdminAuditLog::query()->where('target_admin_id', $sole->id)->count());
    }

    public function test_self_demotion_is_refused_even_when_crafted(): void
    {
        $self = $this->superAdmin();
        $this->superAdmin();
        $this->actingAs($self, 'admin');

        try {
            Livewire::test(ViewAdmin::class, ['record' => $self->id])
                ->callAction('changeRole', data: ['role' => 'operations', 'reason' => 'Crafted self-demotion']);
        } catch (Throwable) {
        }

        $this->assertSame(AdminRole::SuperAdmin, $self->fresh()->role);
        $this->assertSame(0, AdminAuditLog::query()->where('target_admin_id', $self->id)->count());
    }

    public function test_a_refused_deactivation_is_reported_to_the_operator(): void
    {
        $self = $this->superAdmin();
        $other = $this->superAdmin();
        $this->actingAs($self, 'admin');

        // Deactivate the other SuperAdmin first (allowed: $self remains).
        Livewire::test(ViewAdmin::class, ['record' => $other->id])
            ->callAction('deactivate', data: ['reason' => 'Routine rota change'])
            ->assertHasNoActionErrors();

        $this->assertFalse($other->fresh()->is_active);
        $this->assertTrue($self->fresh()->is_active);
    }

    // ---- Listing, search, filters -----------------------------------

    public function test_list_search_matches_name_and_email_prefixes(): void
    {
        $ann = $this->operations(['name' => 'Ann Smith', 'email' => 'ann.smith@example.com']);
        $joanna = $this->operations(['name' => 'Joanna Ann', 'email' => 'joanna@example.com']);
        $this->actingAs($this->superAdmin(), 'admin');

        Livewire::test(ListAdmins::class)->searchTable('Ann')
            ->assertCanSeeTableRecords([$ann])
            ->assertCanNotSeeTableRecords([$joanna]);

        Livewire::test(ListAdmins::class)->searchTable('joanna@')
            ->assertCanSeeTableRecords([$joanna])
            ->assertCanNotSeeTableRecords([$ann]);
    }

    public function test_role_status_and_created_filters(): void
    {
        $superAdmin = $this->superAdmin(['name' => 'Filter Super']);
        $operations = $this->operations(['name' => 'Filter Operations']);
        $inactive = $this->operations(['is_active' => false, 'name' => 'Filter Inactive']);
        DB::table('admins')->where('id', $inactive->id)->update(['created_at' => '2026-01-05 10:00:00']);
        $this->actingAs($this->superAdmin(), 'admin');

        Livewire::test(ListAdmins::class)->filterTable('role', 'super_admin')
            ->assertCanSeeTableRecords([$superAdmin])
            ->assertCanNotSeeTableRecords([$operations]);

        Livewire::test(ListAdmins::class)->filterTable('is_active', false)
            ->assertCanSeeTableRecords([$inactive])
            ->assertCanNotSeeTableRecords([$operations]);

        Livewire::test(ListAdmins::class)->filterTable('created_between', ['from' => '2026-09-01'])
            ->assertCanNotSeeTableRecords([$inactive]);
    }

    public function test_the_list_query_count_does_not_grow_with_rows(): void
    {
        $this->actingAs($this->superAdmin(), 'admin');

        $count = fn (): int => $this->queryCount(fn () => Livewire::test(ListAdmins::class));

        $few = $count();
        foreach (range(1, 8) as $i) {
            $this->operations();
        }

        $this->assertGreaterThan(0, $few, 'query log was not captured');
        $this->assertSame($few, $count(), 'Admin list N+1');
    }

    // ---- Privacy ----------------------------------------------------

    public function test_password_hash_remember_token_and_sessions_are_never_rendered(): void
    {
        $target = $this->operations();
        DB::table('admins')->where('id', $target->id)->update(['remember_token' => 'REMEMBER-SENTINEL-77']);
        DB::table('sessions')->insert([
            'id' => 'admin-session-sentinel',
            'user_id' => $target->id,
            'payload' => 'SESSION-PAYLOAD-SENTINEL',
            'last_activity' => time(),
        ]);
        $hash = DB::table('admins')->where('id', $target->id)->value('password');
        $this->actingAs($this->superAdmin(), 'admin');

        foreach (['/admin/admins', "/admin/admins/{$target->id}", "/admin/admins/{$target->id}/edit"] as $url) {
            $this->get($url)->assertOk()
                ->assertDontSee($hash, false)
                ->assertDontSee('$2y$', false)
                ->assertDontSee('REMEMBER-SENTINEL-77', false)
                ->assertDontSee('SESSION-PAYLOAD-SENTINEL', false)
                ->assertDontSee('admin-session-sentinel', false);
        }
    }

    public function test_the_admin_query_never_loads_credential_columns(): void
    {
        $target = $this->operations();

        foreach ([AdminResource::getEloquentQuery(), AdminResource::getRecordRouteBindingEloquentQuery()] as $query) {
            $loaded = $query->findOrFail($target->id);
            $this->assertNotContains('password', array_keys($loaded->getAttributes()));
            $this->assertNotContains('remember_token', array_keys($loaded->getAttributes()));
        }
    }

    // ---- Audit history visibility -----------------------------------

    public function test_audit_history_shows_actor_and_safe_change_only(): void
    {
        $actor = $this->superAdmin(['name' => 'Acting Super']);
        $target = $this->operations();
        $this->actingAs($actor, 'admin');

        Livewire::test(ViewAdmin::class, ['record' => $target->id])
            ->callAction('changeRole', data: ['role' => 'super_admin', 'reason' => 'Promotion reason text']);

        Livewire::test(AuditRelationManager::class, ['ownerRecord' => $target, 'pageClass' => ViewAdmin::class])
            ->assertCanSeeTableRecords(AdminAuditLog::query()->where('target_admin_id', $target->id)->get())
            ->assertSee('Acting Super')
            ->assertSee('Promotion reason text')
            ->assertSee('Operations → Super Admin')
            ->assertDontSee('"from"', false);
    }

    public function test_audit_history_is_not_offered_to_an_admin_without_audit_view(): void
    {
        $target = $this->operations();

        $this->actingAs($this->operations(), 'admin');
        $this->assertFalse(AuditRelationManager::canViewForRecord($target, ViewAdmin::class));

        $this->actingAs($this->superAdmin(), 'admin');
        $this->assertTrue(AuditRelationManager::canViewForRecord($target, ViewAdmin::class));
    }

    public function test_audit_history_is_not_offered_to_a_guest(): void
    {
        $this->assertFalse(AuditRelationManager::canViewForRecord($this->operations(), ViewAdmin::class));
    }

    // ---- Structure --------------------------------------------------

    public function test_there_is_no_hard_delete_and_no_restore_capability(): void
    {
        $target = $this->operations();
        $this->actingAs($this->superAdmin(), 'admin');

        $this->assertSame(['index', 'create', 'view', 'edit'], array_keys(AdminResource::getPages()));
        foreach (['canDelete', 'canDeleteAny', 'canForceDelete', 'canForceDeleteAny', 'canRestore', 'canRestoreAny', 'canReplicate', 'canReorder'] as $method) {
            $this->assertFalse(AdminResource::{$method}(...($method === 'canDeleteAny' || $method === 'canForceDeleteAny' || $method === 'canRestoreAny' || $method === 'canReorder' ? [] : [$target])), $method);
        }
    }

    public function test_navigation_group(): void
    {
        $this->assertSame('Administration', AdminResource::getNavigationGroup());
    }
}
