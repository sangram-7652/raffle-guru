<?php

namespace App\Services\Winner;

use App\Enums\WinnerClaimStatus;
use App\Enums\WinnerStatus;
use App\Models\Winner;
use App\Models\WinnerAuditLog;
use App\Models\WinnerClaim;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The only place `winner_claims` rows are ever written — see
 * docs/WINNER_CLAIM_P10.md "Claim lifecycle". Owns both the one
 * customer-facing mutation in this whole domain (submit()) and the
 * server-only claim review transitions that a future admin surface
 * would call.
 */
class WinnerClaimService
{
    /**
     * Claim transitions a claim TO may trigger on the parent Winner —
     * see docs/WINNER_CLAIM_P10.md "Claim lifecycle" for why these two
     * state machines are coupled at exactly these two points and
     * nowhere else.
     *
     * @var array<string, WinnerStatus>
     */
    private const WINNER_TRANSITION_ON = [
        WinnerClaimStatus::Verified->value => WinnerStatus::Verified,
        WinnerClaimStatus::Approved->value => WinnerStatus::ClaimApproved,
    ];

    public function __construct(
        private readonly WinnerLifecycleService $winnerLifecycle,
    ) {}

    /**
     * The one customer-initiated write in the winner/claim/fulfilment
     * domain. Ownership is enforced by the caller (controller) before
     * this is ever reached — this method itself only re-validates the
     * winner's STATE, not identity, which is why it never accepts a
     * winner id from the request.
     *
     * Only submittable while the winner is `contacted` (see
     * docs/WINNER_CLAIM_P10.md "Claim rules") — submitting is what
     * kicks off verification, moving the winner to
     * `verification_pending` in the same transaction. Concurrency: the
     * winner row is locked for the whole check-then-create window
     * (same philosophy as WinnerService::createFromDrawResult()), and
     * `winner_claims.winner_id` carries a hard UNIQUE constraint as the
     * database-level backstop — see docs/WINNER_CLAIM_P10.md "Claim
     * concurrency".
     *
     * @throws WinnerClaimException
     */
    public function submit(Winner $winner, ?string $notes): WinnerClaim
    {
        return DB::transaction(function () use ($winner, $notes) {
            /** @var Winner $locked */
            $locked = Winner::query()->lockForUpdate()->findOrFail($winner->id);

            if ($locked->status !== WinnerStatus::Contacted) {
                throw new WinnerClaimException(
                    "Cannot submit a claim for winner {$locked->id}: status is \"{$locked->status->value}\", expected \"contacted\"."
                );
            }

            if (WinnerClaim::query()->where('winner_id', $locked->id)->exists()) {
                throw new WinnerClaimException("Winner {$locked->id} has already submitted a claim.");
            }

            try {
                $claim = WinnerClaim::create([
                    'winner_id' => $locked->id,
                    'status' => WinnerClaimStatus::Submitted,
                    'submitted_at' => now(),
                    'notes' => $notes,
                ]);
            } catch (QueryException $e) {
                // Lost a race despite the row lock above (defensive
                // only, mirrors WinnerService's identical guard).
                throw new WinnerClaimException("Winner {$locked->id} has already submitted a claim.", previous: $e);
            }

            WinnerAuditLog::create([
                'winner_id' => $locked->id,
                'event' => 'claim_submitted',
                'from_status' => null,
                'to_status' => WinnerClaimStatus::Submitted->value,
                'actor_type' => 'customer',
                'actor_id' => $locked->user_id,
            ]);

            // The claim existing IS what starts verification — see the
            // docblock above. A no-op guard rather than an exception:
            // the status check above already guarantees this succeeds.
            $this->winnerLifecycle->transition(
                $locked,
                WinnerStatus::VerificationPending,
                'claim submitted',
                actorType: 'system',
            );

            return $claim;
        });
    }

    /**
     * Server-only claim review transition — never reachable from a
     * customer request (see docs/WINNER_CLAIM_P10.md "Verification").
     * The extension point a future admin/operator surface calls.
     *
     * `$actorId` (P11.9) is the operator (admin) performing the review.
     * It is recorded on this claim's audit row AND on the coupled winner
     * transition's audit row, so an operator decision is attributable
     * end to end. Null keeps the pre-P11.9 behaviour (operator, no id).
     *
     * @throws WinnerClaimException
     */
    public function transition(WinnerClaim $claim, WinnerClaimStatus $to, string $reason, ?string $rejectionReason = null, ?int $actorId = null): WinnerClaim
    {
        return DB::transaction(function () use ($claim, $to, $reason, $rejectionReason, $actorId) {
            /** @var WinnerClaim $locked */
            $locked = WinnerClaim::query()->lockForUpdate()->findOrFail($claim->id);
            $from = $locked->status;

            if (! $this->canTransition($from, $to)) {
                throw new WinnerClaimException("Cannot move claim {$locked->id} from \"{$from->value}\" to \"{$to->value}\".");
            }

            $update = ['status' => $to];
            if ($to === WinnerClaimStatus::Verified) {
                $update['verified_at'] = now();
            }
            if ($to === WinnerClaimStatus::Rejected) {
                $update['rejected_at'] = now();
                $update['rejection_reason'] = $rejectionReason;
            }
            $locked->update($update);

            WinnerAuditLog::create([
                'winner_id' => $locked->winner_id,
                'event' => match ($to) {
                    WinnerClaimStatus::VerificationPending => 'verification_started',
                    WinnerClaimStatus::Verified => 'winner_verified',
                    WinnerClaimStatus::Approved => 'claim_approved',
                    WinnerClaimStatus::Rejected => 'claim_rejected',
                    default => 'claim_status_changed',
                },
                'from_status' => $from->value,
                'to_status' => $to->value,
                'actor_type' => 'operator',
                'actor_id' => $actorId,
                'metadata' => ['reason' => $reason],
            ]);

            if (isset(self::WINNER_TRANSITION_ON[$to->value])) {
                /** @var Winner $winner */
                $winner = $locked->winner()->lockForUpdate()->firstOrFail();
                $this->winnerLifecycle->transition(
                    $winner,
                    self::WINNER_TRANSITION_ON[$to->value],
                    "claim {$to->value}",
                    actorType: 'operator',
                    actorId: $actorId,
                );
            }

            return $locked->fresh();
        });
    }

    /**
     * Public (P11.9) so an admin surface can offer only currently-valid
     * moves by asking the service, rather than re-implementing this
     * matrix. transition() still re-checks it under the row lock.
     */
    public function canTransition(WinnerClaimStatus $from, WinnerClaimStatus $to): bool
    {
        return match ($from) {
            WinnerClaimStatus::Submitted => $to === WinnerClaimStatus::VerificationPending,
            WinnerClaimStatus::VerificationPending => $to === WinnerClaimStatus::Verified,
            WinnerClaimStatus::Verified => in_array($to, [WinnerClaimStatus::Approved, WinnerClaimStatus::Rejected], true),
            default => false,
        };
    }
}
