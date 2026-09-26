<?php

namespace Tests\Feature\Admin;

use App\Enums\WinnerClaimStatus;
use App\Enums\WinnerStatus;
use App\Filament\Resources\WinnerClaims\Pages\ListWinnerClaims;
use App\Filament\Resources\WinnerClaims\Pages\ViewWinnerClaim;
use App\Filament\Resources\WinnerClaims\WinnerClaimResource;
use App\Models\Admin;
use App\Models\Winner;
use App\Models\WinnerAuditLog;
use App\Models\WinnerClaim;
use App\Policies\WinnerClaimPolicy;
use App\Services\Winner\WinnerClaimService;
use App\Services\Winner\WinnerLifecycleService;
use App\Services\Winner\WinnerService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Feature\Winner\Concerns\CreatesWinnerFixtures;
use Tests\TestCase;
use Throwable;

/**
 * P11.9 — WinnerClaimResource: read-only claims plus the review actions,
 * every one through the REAL WinnerClaimService (claim transition, the
 * coupled winner transition, audit rows with the admin's ID).
 */
class WinnerClaimResourceTest extends TestCase
{
    use CreatesWinnerFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /**
     * A claim advanced to $status only through the real P10 services.
     */
    private function claim(WinnerClaimStatus $status = WinnerClaimStatus::Submitted): WinnerClaim
    {
        ['competition' => $competition] = $this->createDrawnCompetition();
        $winner = app(WinnerService::class)->createFromDrawResult($competition);
        app(WinnerLifecycleService::class)->transition($winner, WinnerStatus::Contacted, 'setup');
        $claim = app(WinnerClaimService::class)->submit($winner->fresh(), 'Please send to my home address.');

        $path = [WinnerClaimStatus::VerificationPending, WinnerClaimStatus::Verified];
        foreach ($path as $step) {
            if ($claim->status === $status) {
                break;
            }
            $claim = app(WinnerClaimService::class)->transition($claim, $step, 'setup');
        }

        if (in_array($status, [WinnerClaimStatus::Approved, WinnerClaimStatus::Rejected], true)) {
            $claim = app(WinnerClaimService::class)->transition($claim, $status, 'setup', 'setup reason');
        }

        return $claim->fresh();
    }

    private function claimPage(WinnerClaim $claim): Testable
    {
        return Livewire::test(ViewWinnerClaim::class, ['record' => $claim->id]);
    }

    // ---- Access -----------------------------------------------------

    public function test_guest_customer_and_inactive_admin_are_denied(): void
    {
        $claim = $this->claim();

        $this->get('/admin/winner-claims')->assertRedirect('/admin/login');
        $this->get("/admin/winner-claims/{$claim->id}")->assertRedirect('/admin/login');

        $this->actingAs($claim->winner->user, 'web')->get('/admin/winner-claims')->assertRedirect('/admin/login');

        $this->actingAs(Admin::factory()->inactive()->create(), 'admin');
        $this->get('/admin/winner-claims')->assertForbidden();
        $this->get("/admin/winner-claims/{$claim->id}")->assertForbidden();
    }

    public function test_operations_and_super_admin_can_list_and_view(): void
    {
        $claim = $this->claim();

        foreach ([Admin::factory()->create(), Admin::factory()->superAdmin()->create()] as $admin) {
            $this->actingAs($admin, 'admin');

            $this->get('/admin/winner-claims')->assertOk()->assertSee("#{$claim->id}");
            $this->get("/admin/winner-claims/{$claim->id}")->assertOk()->assertSee('Please send to my home address.');
        }
    }

    public function test_admin_denied_by_claim_policy_gets_403(): void
    {
        Gate::policy(WinnerClaim::class, DenyClaimPolicy::class);
        $claim = $this->claim();
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        $this->get('/admin/winner-claims')->assertForbidden();
        $this->get("/admin/winner-claims/{$claim->id}")->assertForbidden();
    }

    // ---- Read-only enforcement --------------------------------------

    public function test_no_create_edit_delete_routes_capabilities_or_bulk_actions(): void
    {
        $claim = $this->claim();
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        $this->assertSame(['index', 'view'], array_keys(WinnerClaimResource::getPages()));
        $this->get('/admin/winner-claims/create')->assertNotFound();
        $this->get("/admin/winner-claims/{$claim->id}/edit")->assertNotFound();

        $this->assertFalse(WinnerClaimResource::canCreate());
        foreach (['canEdit', 'canDelete', 'canForceDelete', 'canRestore', 'canReplicate'] as $method) {
            $this->assertFalse(WinnerClaimResource::{$method}($claim), $method);
        }

        $table = Livewire::test(ListWinnerClaims::class)->instance()->getTable();
        $this->assertSame(['view'], array_keys($table->getFlatActions()));
        $this->assertSame([], $table->getBulkActions());
    }

    public function test_crafted_livewire_requests_cannot_mutate_the_claim(): void
    {
        $claim = $this->claim(WinnerClaimStatus::Verified);
        $other = $this->claim();
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');
        $before = [DB::table('winner_claims')->orderBy('id')->get(), DB::table('winners')->orderBy('id')->get()];

        $attempts = [
            fn () => $this->claimPage($claim)->set('record.status', WinnerClaimStatus::Approved->value),
            fn () => $this->claimPage($claim)->set('record.winner_id', $other->winner_id),
            fn () => $this->claimPage($claim)->set('record.notes', 'forged'),
            fn () => $this->claimPage($claim)->set('data.status', 'approved')->call('save'),
            fn () => $this->claimPage($claim)->call('mountAction', 'delete')->call('callMountedAction'),
            fn () => Livewire::test(ListWinnerClaims::class)->call('mountTableAction', 'delete', (string) $claim->id)->call('callMountedTableAction'),
            // An action whose form is invalid (note too short) plus forged
            // target fields: nothing may change.
            fn () => $this->claimPage($claim)->call('mountAction', 'approveClaim')
                ->set('mountedActions.0.data.note', 'x')
                ->set('mountedActions.0.data.status', 'approved')
                ->set('mountedActions.0.data.winner_id', $other->winner_id)
                ->call('callMountedAction'),
        ];

        foreach ($attempts as $attempt) {
            try {
                $attempt();
            } catch (Throwable) {
                // Refused outright — also a pass; the DB check is what matters.
            }
        }

        $this->assertEquals($before, [DB::table('winner_claims')->orderBy('id')->get(), DB::table('winners')->orderBy('id')->get()]);
    }

    // ---- Content ------------------------------------------------------

    public function test_view_shows_winner_customer_and_status(): void
    {
        $claim = $this->claim(WinnerClaimStatus::VerificationPending);
        $winner = $claim->winner;
        $this->actingAs(Admin::factory()->create(), 'admin');

        $this->get("/admin/winner-claims/{$claim->id}")
            ->assertOk()
            ->assertSee('Verification Pending')
            ->assertSee($winner->user->name)
            ->assertSee($winner->user->email)
            ->assertSee($winner->competition->title)
            ->assertSee("/admin/winners/{$winner->id}", false)
            ->assertSee("/admin/tickets/{$winner->ticket_id}", false);
    }

    public function test_sensitive_fields_are_never_exposed(): void
    {
        $claim = $this->claim();
        DB::table('users')->where('id', $claim->winner->user_id)->update(['remember_token' => 'REMEMBER-TOKEN-SENTINEL']);
        DB::table('winner_claims')->where('id', $claim->id)->update(['metadata' => json_encode(['internal' => 'CLAIM-META-SENTINEL'])]);
        $hash = DB::table('users')->where('id', $claim->winner->user_id)->value('password');
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        foreach (['/admin/winner-claims', "/admin/winner-claims/{$claim->id}"] as $url) {
            $this->get($url)->assertOk()
                ->assertDontSee($hash, false)
                ->assertDontSee('REMEMBER-TOKEN-SENTINEL', false)
                ->assertDontSee('CLAIM-META-SENTINEL', false);
        }

        $user = WinnerClaimResource::getEloquentQuery()->findOrFail($claim->id)->winner->user;
        $this->assertSame(['id', 'name', 'email'], array_keys($user->getAttributes()));
    }

    // ---- Review actions (real WinnerClaimService) -------------------

    public function test_full_review_path_through_the_real_service_with_attribution(): void
    {
        $claim = $this->claim();
        $admin = Admin::factory()->create(); // Operations holds claim.verify/approve
        $this->actingAs($admin, 'admin');

        $this->claimPage($claim)->callAction('startVerification', data: ['note' => 'Reviewing documents'])->assertHasNoActionErrors();
        $this->assertSame(WinnerClaimStatus::VerificationPending, $claim->fresh()->status);

        $this->claimPage($claim)->callAction('verifyClaim', data: ['note' => 'Identity confirmed by phone'])->assertHasNoActionErrors();
        $claim->refresh();
        $this->assertSame(WinnerClaimStatus::Verified, $claim->status);
        $this->assertNotNull($claim->verified_at);
        $this->assertSame(WinnerStatus::Verified, $claim->winner->fresh()->status); // coupled, by the service

        $this->claimPage($claim)->callAction('approveClaim', data: ['note' => 'All good'])->assertHasNoActionErrors();
        $this->assertSame(WinnerClaimStatus::Approved, $claim->fresh()->status);
        $this->assertSame(WinnerStatus::ClaimApproved, $claim->winner->fresh()->status);

        // Every operator audit row for this review carries the admin's ID.
        $operatorRows = WinnerAuditLog::query()->where('winner_id', $claim->winner_id)->where('actor_type', 'operator')->get();
        $this->assertSame(
            ['verification_started', 'winner_verified', 'winner_status_changed', 'claim_approved', 'winner_status_changed'],
            $operatorRows->pluck('event')->all(),
        );
        $this->assertSame([$admin->id], $operatorRows->pluck('actor_id')->unique()->values()->all());
        $this->assertSame('Identity confirmed by phone', $operatorRows->firstWhere('event', 'winner_verified')->metadata['reason']);
    }

    public function test_reject_stores_the_customer_facing_reason(): void
    {
        $claim = $this->claim(WinnerClaimStatus::Verified);
        $admin = Admin::factory()->superAdmin()->create();
        $this->actingAs($admin, 'admin');

        $this->claimPage($claim)
            ->callAction('rejectClaim', data: ['note' => 'A valid internal note'])
            ->assertHasActionErrors(['rejection_reason' => 'required']);

        $this->claimPage($claim)
            ->callAction('rejectClaim', data: [
                'rejection_reason' => 'The ticket holder could not be verified.',
                'note' => 'Failed ID check twice',
            ])
            ->assertHasNoActionErrors();

        $claim->refresh();
        $this->assertSame(WinnerClaimStatus::Rejected, $claim->status);
        $this->assertSame('The ticket holder could not be verified.', $claim->rejection_reason);
        $this->assertNotNull($claim->rejected_at);
        // P10: rejection does not move the winner.
        $this->assertSame(WinnerStatus::Verified, $claim->winner->fresh()->status);
        $this->assertSame($admin->id, WinnerAuditLog::query()->where('event', 'claim_rejected')->value('actor_id'));

        // The customer sees exactly that reason through their own API.
        $this->actingAs($claim->winner->user, 'web')
            ->getJson("/api/v1/account/winners/{$claim->winner_id}")
            ->assertJsonPath('data.claim.rejection_reason', 'The ticket holder could not be verified.');
    }

    public function test_only_service_valid_actions_are_offered(): void
    {
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');
        $all = ['startVerification', 'verifyClaim', 'approveClaim', 'rejectClaim'];

        $expected = [
            WinnerClaimStatus::Submitted->value => ['startVerification'],
            WinnerClaimStatus::VerificationPending->value => ['verifyClaim'],
            WinnerClaimStatus::Verified->value => ['approveClaim', 'rejectClaim'],
            WinnerClaimStatus::Approved->value => [],
            WinnerClaimStatus::Rejected->value => [],
        ];

        foreach ($expected as $status => $visible) {
            $page = $this->claimPage($this->claim(WinnerClaimStatus::from($status)));

            foreach ($all as $action) {
                in_array($action, $visible, true)
                    ? $page->assertActionVisible($action)
                    : $page->assertActionHidden($action);
            }
        }
    }

    public function test_invalid_transition_is_rejected_by_the_real_service(): void
    {
        $claim = $this->claim();
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        $raw = fn (string $action) => $this->claimPage($claim)
            ->call('mountAction', $action)
            ->set('mountedActions.0.data.note', 'Raw request note')
            ->set('mountedActions.0.data.rejection_reason', 'Raw rejection reason')
            ->call('callMountedAction');

        // Positive control on the raw path, then skipping ahead is refused.
        $raw('startVerification');
        $this->assertSame(WinnerClaimStatus::VerificationPending, $claim->fresh()->status);

        $raw('approveClaim');
        $raw('rejectClaim');
        $this->assertSame(WinnerClaimStatus::VerificationPending, $claim->fresh()->status);
        $this->assertSame(WinnerStatus::VerificationPending, $claim->winner->fresh()->status);
    }

    /**
     * P11.9 §37, layer 1 — another admin approved after this page loaded.
     * The next request re-reads the claim, Reject is no longer valid, and
     * Filament refuses the stale action before anything runs.
     */
    public function test_stale_page_cannot_apply_an_outdated_action(): void
    {
        $claim = $this->claim(WinnerClaimStatus::Verified);
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        $page = $this->claimPage($claim)->mountAction('rejectClaim');

        app(WinnerClaimService::class)->transition($claim, WinnerClaimStatus::Approved, 'other admin', actorId: 999);

        $page->setActionData(['rejection_reason' => 'Too late reason', 'note' => 'Stale rejection attempt'])
            ->callMountedAction();

        $claim->refresh();
        $this->assertSame(WinnerClaimStatus::Approved, $claim->status);
        $this->assertNull($claim->rejection_reason);
        $this->assertSame(0, WinnerAuditLog::query()->where('event', 'claim_rejected')->count());
    }

    /**
     * P11.9 §37, layer 2 — the race lands AFTER the UI check but before
     * the service's row lock. WinnerClaimService re-reads the locked row
     * and refuses; the admin is told the truth.
     */
    public function test_race_before_the_row_lock_is_rejected_by_the_service(): void
    {
        $claim = $this->claim(WinnerClaimStatus::Verified);
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        $this->app->instance(WinnerClaimService::class, new class(app(WinnerLifecycleService::class)) extends WinnerClaimService
        {
            private bool $raced = false;

            public function transition(WinnerClaim $claim, WinnerClaimStatus $to, string $reason, ?string $rejectionReason = null, ?int $actorId = null): WinnerClaim
            {
                if (! $this->raced) {
                    $this->raced = true;
                    parent::transition($claim, WinnerClaimStatus::Approved, 'other admin', actorId: 999);
                }

                return parent::transition($claim, $to, $reason, $rejectionReason, $actorId);
            }
        });

        $this->claimPage($claim)
            ->callAction('rejectClaim', data: ['rejection_reason' => 'Too late reason', 'note' => 'Racing rejection'])
            ->assertNotified('Reject not applied');

        $claim->refresh();
        $this->assertSame(WinnerClaimStatus::Approved, $claim->status);
        $this->assertNull($claim->rejection_reason);
        $this->assertSame(WinnerStatus::ClaimApproved, $claim->winner->fresh()->status);
    }

    public function test_each_action_requires_its_own_ability(): void
    {
        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin');

        foreach ([
            ['verify', WinnerClaimStatus::Submitted, 'startVerification'],
            ['verify', WinnerClaimStatus::VerificationPending, 'verifyClaim'],
            ['approve', WinnerClaimStatus::Verified, 'approveClaim'],
            ['reject', WinnerClaimStatus::Verified, 'rejectClaim'],
        ] as [$denied, $status, $action]) {
            Gate::policy(WinnerClaim::class, DenyOneClaimAbilityPolicy::for($denied));
            $claim = $this->claim($status);

            $this->claimPage($claim)
                ->assertActionHidden($action)
                ->call('mountAction', $action)
                ->set('mountedActions.0.data.note', 'Crafted request note')
                ->set('mountedActions.0.data.rejection_reason', 'Crafted reason')
                ->call('callMountedAction');

            $this->assertSame($status, $claim->fresh()->status, "{$action} ran without {$denied}");
        }
    }

    // ---- Table ------------------------------------------------------

    public function test_filters_and_search(): void
    {
        $submitted = $this->claim();
        $verified = $this->claim(WinnerClaimStatus::Verified);
        DB::table('users')->where('id', $verified->winner->user_id)->update(['email' => 'verified.claimant@example.com']);
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(ListWinnerClaims::class)
            ->filterTable('status', [WinnerClaimStatus::Verified->value])
            ->assertCanSeeTableRecords([$verified])
            ->assertCanNotSeeTableRecords([$submitted]);

        Livewire::test(ListWinnerClaims::class)
            ->filterTable('competition', ['competition_id' => $submitted->winner->competition_id])
            ->assertCanSeeTableRecords([$submitted])
            ->assertCanNotSeeTableRecords([$verified]);

        foreach (["#{$verified->id}", 'verified.claimant'] as $term) {
            Livewire::test(ListWinnerClaims::class)
                ->searchTable($term)
                ->assertCanSeeTableRecords([$verified])
                ->assertCanNotSeeTableRecords([$submitted]);
        }
    }

    public function test_list_query_count_does_not_grow_with_rows(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');

        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::test(ListWinnerClaims::class);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $this->claim();
        $few = $count();
        foreach (range(1, 6) as $i) {
            $this->claim(WinnerClaimStatus::Verified);
        }

        $this->assertSame($few, $count(), 'Claim list N+1');
    }

    public function test_navigation(): void
    {
        $this->assertSame('Winners', WinnerClaimResource::getNavigationGroup());
        $this->assertSame('Claims', WinnerClaimResource::getNavigationLabel());
    }
}

class DenyClaimPolicy extends WinnerClaimPolicy
{
    public function viewAny(Admin $admin): bool
    {
        return false;
    }

    public function view(Admin $admin, WinnerClaim $claim): bool
    {
        return false;
    }
}

/**
 * Denies exactly one review ability, keeping the others — proves each
 * action checks its OWN policy method.
 */
class DenyOneClaimAbilityPolicy extends WinnerClaimPolicy
{
    public static string $denied = '';

    public static function for(string $ability): string
    {
        self::$denied = $ability;

        return self::class;
    }

    public function verify(Admin $admin, WinnerClaim $claim): bool
    {
        return self::$denied !== 'verify' && parent::verify($admin, $claim);
    }

    public function approve(Admin $admin, WinnerClaim $claim): bool
    {
        return self::$denied !== 'approve' && parent::approve($admin, $claim);
    }

    public function reject(Admin $admin, WinnerClaim $claim): bool
    {
        return self::$denied !== 'reject' && parent::reject($admin, $claim);
    }
}
