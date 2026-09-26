<?php

namespace Tests\Feature\Winner;

use App\Enums\WinnerClaimStatus;
use App\Enums\WinnerStatus;
use App\Models\WinnerAuditLog;
use App\Models\WinnerClaim;
use App\Services\Winner\WinnerClaimException;
use App\Services\Winner\WinnerClaimService;
use App\Services\Winner\WinnerLifecycleService;
use App\Services\Winner\WinnerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Winner\Concerns\CreatesWinnerFixtures;
use Tests\TestCase;

/**
 * P11.9 domain extension to WinnerClaimService: optional operator
 * attribution on claim review, and a public canTransition() so an admin
 * surface can ask the service which moves are valid.
 */
class WinnerClaimActorAttributionTest extends TestCase
{
    use CreatesWinnerFixtures, RefreshDatabase;

    private function submittedClaim(): WinnerClaim
    {
        ['competition' => $competition] = $this->createDrawnCompetition();
        $winner = app(WinnerService::class)->createFromDrawResult($competition);
        app(WinnerLifecycleService::class)->transition($winner, WinnerStatus::Contacted, 'test');

        return app(WinnerClaimService::class)->submit($winner->fresh(), 'my claim');
    }

    public function test_actor_id_is_recorded_on_the_claim_and_coupled_winner_audit_rows(): void
    {
        $claim = $this->submittedClaim();
        $service = app(WinnerClaimService::class);

        $service->transition($claim, WinnerClaimStatus::VerificationPending, 'start', actorId: 41);
        $service->transition($claim, WinnerClaimStatus::Verified, 'checked', actorId: 42);

        $claimRow = WinnerAuditLog::query()->where('event', 'winner_verified')->sole();
        $this->assertSame('operator', $claimRow->actor_type);
        $this->assertSame(42, $claimRow->actor_id);

        // The coupled verification_pending -> verified winner transition.
        $winnerRow = WinnerAuditLog::query()
            ->where('event', 'winner_status_changed')
            ->where('to_status', WinnerStatus::Verified->value)
            ->sole();
        $this->assertSame('operator', $winnerRow->actor_type);
        $this->assertSame(42, $winnerRow->actor_id);

        $this->assertSame(41, WinnerAuditLog::query()->where('event', 'verification_started')->value('actor_id'));
    }

    public function test_omitting_the_actor_keeps_pre_p11_9_behaviour(): void
    {
        $claim = $this->submittedClaim();

        app(WinnerClaimService::class)->transition($claim, WinnerClaimStatus::VerificationPending, 'start');

        $row = WinnerAuditLog::query()->where('event', 'verification_started')->sole();
        $this->assertSame('operator', $row->actor_type);
        $this->assertNull($row->actor_id);
    }

    public function test_public_can_transition_matches_what_transition_enforces(): void
    {
        $service = app(WinnerClaimService::class);

        foreach (WinnerClaimStatus::cases() as $from) {
            foreach (WinnerClaimStatus::cases() as $to) {
                $expected = match ($from) {
                    WinnerClaimStatus::Submitted => $to === WinnerClaimStatus::VerificationPending,
                    WinnerClaimStatus::VerificationPending => $to === WinnerClaimStatus::Verified,
                    WinnerClaimStatus::Verified => in_array($to, [WinnerClaimStatus::Approved, WinnerClaimStatus::Rejected], true),
                    default => false,
                };
                $this->assertSame($expected, $service->canTransition($from, $to), "{$from->value} -> {$to->value}");
            }
        }

        // And transition() still refuses what canTransition() refuses.
        $claim = $this->submittedClaim();
        $this->expectException(WinnerClaimException::class);
        $service->transition($claim, WinnerClaimStatus::Approved, 'skip ahead', actorId: 1);
    }
}
