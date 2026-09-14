<?php

namespace App\Services\Winner;

use App\Enums\WinnerStatus;
use App\Models\Winner;
use App\Models\WinnerAuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The only place `winners.status` is ever written — mirrors
 * App\Services\Competitions\CompetitionLifecycleService (P8) exactly.
 * See docs/WINNER_CLAIM_P10.md "Winner lifecycle".
 *
 * No status update happens directly in a controller, request, job, or
 * model — everything routes through transition().
 */
class WinnerLifecycleService
{
    /**
     * Every allowed (from, to) pair. Strictly forward, one step at a
     * time — no backward transitions, no arbitrary jumps. Anything not
     * listed here is rejected.
     *
     * @var array<string, list<WinnerStatus>>
     */
    private const MATRIX = [
        WinnerStatus::PendingContact->value => [WinnerStatus::Contacted],
        WinnerStatus::Contacted->value => [WinnerStatus::VerificationPending],
        WinnerStatus::VerificationPending->value => [WinnerStatus::Verified],
        WinnerStatus::Verified->value => [WinnerStatus::ClaimApproved],
        WinnerStatus::ClaimApproved->value => [WinnerStatus::DeliveryPending],
        WinnerStatus::DeliveryPending->value => [WinnerStatus::Delivered],
        WinnerStatus::Delivered->value => [WinnerStatus::Completed],
    ];

    /**
     * Which timestamp column a transition TO a given status stamps —
     * matches the columns suggested by docs/WINNER_CLAIM_P10.md and the
     * `winners` migration. `pending_contact` has none (it's the row's
     * initial state, stamped by `created_at` already).
     *
     * @var array<string, string>
     */
    private const TIMESTAMP_COLUMN = [
        WinnerStatus::Contacted->value => 'contacted_at',
        WinnerStatus::Verified->value => 'verified_at',
        WinnerStatus::ClaimApproved->value => 'claim_approved_at',
        WinnerStatus::DeliveryPending->value => 'delivery_pending_at',
        WinnerStatus::Delivered->value => 'delivered_at',
        WinnerStatus::Completed->value => 'completed_at',
    ];

    public function canTransition(WinnerStatus $from, WinnerStatus $to): bool
    {
        return in_array($to, self::MATRIX[$from->value] ?? [], true);
    }

    /**
     * Locks the winner row, re-reads its CURRENT status, and applies the
     * transition only if still valid against the matrix. Returns false
     * — a safe no-op, never an exception — for both "invalid
     * transition" and "already transitioned"; this is what makes
     * calling it repeatedly (two racing operator actions, a retried
     * job) idempotent. Safe to call from inside an already-open
     * transaction.
     *
     * `actorType` is 'system' (automatic, driven by another domain
     * service reacting to its own event — e.g. a claim being verified)
     * or 'operator' (a future admin/domain-service call). It is never
     * 'customer': no controller in this codebase calls this method with
     * customer-supplied status data — see
     * docs/WINNER_CLAIM_P10.md "Authorization".
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public function transition(
        Winner $winner,
        WinnerStatus $to,
        string $reason,
        string $actorType = 'system',
        ?int $actorId = null,
        ?array $metadata = null,
    ): bool {
        return DB::transaction(function () use ($winner, $to, $reason, $actorType, $actorId, $metadata) {
            /** @var Winner $locked */
            $locked = Winner::query()->lockForUpdate()->findOrFail($winner->id);
            $from = $locked->status;

            if (! $this->canTransition($from, $to)) {
                return false;
            }

            $update = ['status' => $to];
            if (isset(self::TIMESTAMP_COLUMN[$to->value])) {
                $update[self::TIMESTAMP_COLUMN[$to->value]] = now();
            }
            $locked->update($update);

            WinnerAuditLog::create([
                'winner_id' => $locked->id,
                'event' => 'winner_status_changed',
                'from_status' => $from->value,
                'to_status' => $to->value,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'metadata' => $metadata === null ? ['reason' => $reason] : array_merge(['reason' => $reason], $metadata),
            ]);

            Log::info('winner.lifecycle.transitioned', [
                'winner_id' => $locked->id,
                'competition_id' => $locked->competition_id,
                'from' => $from->value,
                'to' => $to->value,
                'reason' => $reason,
            ]);

            return true;
        });
    }
}
