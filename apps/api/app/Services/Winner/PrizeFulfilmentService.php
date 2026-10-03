<?php

namespace App\Services\Winner;

use App\Enums\CompetitionStatus;
use App\Enums\PrizeDeliveryStatus;
use App\Enums\WinnerStatus;
use App\Models\Competition;
use App\Models\Prize;
use App\Models\PrizeDelivery;
use App\Models\Winner;
use App\Models\WinnerAuditLog;
use App\Services\Competitions\CompetitionLifecycleService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Owns prize delivery creation and progression — see
 * docs/WINNER_CLAIM_P10.md "Fulfilment" and "Competition completion".
 * Winner status and PrizeDelivery status are deliberately kept separate
 * (see the enums' own docblocks); this service is what keeps them in
 * sync at exactly the points the business flow requires it.
 *
 * Manual fulfilment only — no courier API integration exists or is
 * simulated. `delivery_method`/`tracking_reference` are operator-entered
 * facts, never generated here.
 */
class PrizeFulfilmentService
{
    /**
     * @var array<string, list<PrizeDeliveryStatus>>
     */
    private const MATRIX = [
        PrizeDeliveryStatus::Pending->value => [PrizeDeliveryStatus::Processing, PrizeDeliveryStatus::Failed],
        PrizeDeliveryStatus::Processing->value => [PrizeDeliveryStatus::Shipped, PrizeDeliveryStatus::Failed],
        PrizeDeliveryStatus::Shipped->value => [PrizeDeliveryStatus::Delivered, PrizeDeliveryStatus::Failed],
    ];

    public function __construct(
        private readonly PrizeResolutionService $prizeResolution,
        private readonly WinnerLifecycleService $winnerLifecycle,
        private readonly CompetitionLifecycleService $competitionLifecycle,
    ) {}

    /**
     * claim_approved -> delivery_pending. Server-only; never reachable
     * from a customer request.
     */
    public function markDeliveryPending(Winner $winner, string $reason = 'claim approved', ?int $actorId = null): bool
    {
        return $this->winnerLifecycle->transition($winner, WinnerStatus::DeliveryPending, $reason, actorType: 'operator', actorId: $actorId);
    }

    /**
     * Admin fulfilment entry point (P11.10): claim_approved -> delivery_pending
     * and creation of the one PrizeDelivery, as a single transaction. If
     * either step is refused the whole operation rolls back, so no winner
     * ever sits in delivery_pending without a delivery it was meant to get.
     *
     * $prize is required only when the competition has several prizes
     * (see prizeSelectionRequired()); otherwise PrizeResolutionService
     * resolves it, exactly as before.
     *
     * @throws WinnerException|PrizeResolutionException
     */
    public function startFulfilment(Winner $winner, int $actorId, string $reason, ?Prize $prize = null): PrizeDelivery
    {
        return DB::transaction(function () use ($winner, $actorId, $reason, $prize) {
            /** @var Winner $locked */
            $locked = Winner::query()->lockForUpdate()->findOrFail($winner->id);

            if ($locked->status === WinnerStatus::ClaimApproved) {
                if (! $this->markDeliveryPending($locked, $reason, $actorId)) {
                    throw new WinnerException("Winner {$locked->id} could not move to delivery_pending.");
                }
                $locked = $locked->fresh();
            }

            return $this->createDelivery($locked, $prize, $actorId);
        });
    }

    /**
     * Whether an admin must choose the prize for this competition's
     * winner. Only a competition with several prizes is ambiguous (see
     * PrizeResolutionService); zero prizes cannot be fulfilled at all.
     */
    public function prizeSelectionRequired(Competition $competition): bool
    {
        return Prize::query()->where('competition_id', $competition->id)->count() > 1;
    }

    /**
     * The prizes an admin may choose for this competition's winner: the
     * competition's own live prizes that still have stock left. A prize
     * from another competition can never appear here, and neither can a
     * prize whose quantity is used up.
     *
     * @return Collection<int, Prize>
     */
    public function assignablePrizes(Competition $competition): Collection
    {
        return Prize::query()
            ->where('competition_id', $competition->id)
            ->get()
            ->filter(fn (Prize $prize): bool => $this->remainingQuantity($prize) > 0)
            ->values();
    }

    /**
     * Whether startFulfilment() could currently be applied to this winner.
     * The service re-checks under lock; this only drives button visibility.
     */
    public function canStartFulfilment(Winner $winner): bool
    {
        if ($winner->status === WinnerStatus::ClaimApproved) {
            return true;
        }

        return $winner->status === WinnerStatus::DeliveryPending
            && ! PrizeDelivery::query()->where('winner_id', $winner->id)->exists();
    }

    /**
     * Legal next delivery statuses — the single source of truth the admin
     * UI asks, instead of copying MATRIX.
     *
     * @return list<PrizeDeliveryStatus>
     */
    public function allowedNextStatuses(PrizeDeliveryStatus $from): array
    {
        return self::MATRIX[$from->value] ?? [];
    }

    public function canTransition(PrizeDeliveryStatus $from, PrizeDeliveryStatus $to): bool
    {
        return in_array($to, $this->allowedNextStatuses($from), true);
    }

    /**
     * Creates the (at most one, ever) PrizeDelivery for a winner already
     * `delivery_pending`. Locks the winner row for the whole
     * check-then-create window — same philosophy as
     * WinnerService::createFromDrawResult() — with
     * `prize_deliveries.winner_id`'s UNIQUE constraint as the hard
     * database backstop. See docs/WINNER_CLAIM_P10.md "Concurrency" (E).
     *
     * Prize selection:
     * - $prize null: resolved by PrizeResolutionService. A competition
     *   with several prizes is refused and nothing is created — the
     *   P10 ambiguity protection, unchanged.
     * - $prize given (P11.10 admin assignment): must belong to this
     *   competition, must not be trashed, and must still have stock. Its
     *   row is locked so two winners cannot take the last unit at once.
     *
     * An existing delivery is never reassigned: the same prize returns
     * it unchanged; a different prize is refused as a conflict.
     *
     * @throws PrizeResolutionException|WinnerException
     */
    public function createDelivery(Winner $winner, ?Prize $prize = null, ?int $actorId = null): PrizeDelivery
    {
        $adminChosen = $prize !== null;

        return DB::transaction(function () use ($winner, $prize, $actorId, $adminChosen) {
            /** @var Winner $locked */
            $locked = Winner::query()->lockForUpdate()->findOrFail($winner->id);

            if ($locked->status !== WinnerStatus::DeliveryPending) {
                throw new WinnerException("Cannot create a delivery for winner {$locked->id}: status is \"{$locked->status->value}\", expected \"delivery_pending\".");
            }

            $existing = PrizeDelivery::query()->where('winner_id', $locked->id)->first();
            if ($existing !== null) {
                if ($prize !== null && $existing->prize_id !== $prize->id) {
                    throw new WinnerException("Winner {$locked->id} already has a delivery for a different prize; it cannot be reassigned.");
                }

                return $existing;
            }

            if ($prize !== null) {
                $prize = $this->lockAssignablePrize($locked, $prize);
            } else {
                try {
                    $prize = $this->prizeResolution->resolveForCompetition($locked->competition);
                } catch (PrizeResolutionException $e) {
                    WinnerAuditLog::create([
                        'winner_id' => $locked->id,
                        'event' => 'delivery_prize_unresolved',
                        'actor_type' => 'system',
                        'metadata' => ['reason' => $e->getMessage()],
                    ]);

                    throw $e;
                }
            }

            try {
                $delivery = PrizeDelivery::create([
                    'winner_id' => $locked->id,
                    'prize_id' => $prize->id,
                    'status' => PrizeDeliveryStatus::Pending,
                ]);
            } catch (QueryException $e) {
                $again = PrizeDelivery::query()->where('winner_id', $locked->id)->first();
                if ($again !== null) {
                    return $again;
                }

                throw $e;
            }

            WinnerAuditLog::create([
                'winner_id' => $locked->id,
                'event' => 'delivery_created',
                'actor_type' => 'operator',
                'actor_id' => $actorId,
                'metadata' => [
                    'prize_id' => $prize->id,
                    'delivery_id' => $delivery->id,
                    'selection' => $adminChosen ? 'admin_assigned' : 'resolved',
                ],
            ]);

            return $delivery;
        });
    }

    /**
     * Locks the chosen prize row and checks it may be given to this winner.
     *
     * @throws WinnerException
     */
    private function lockAssignablePrize(Winner $winner, Prize $prize): Prize
    {
        /** @var Prize $locked */
        $locked = Prize::query()->lockForUpdate()->findOrFail($prize->id);

        if ($locked->competition_id !== $winner->competition_id) {
            throw new WinnerException("Prize {$locked->id} does not belong to this winner's competition.");
        }

        if ($this->remainingQuantity($locked) < 1) {
            throw new WinnerException("Prize {$locked->id} has no remaining quantity to assign.");
        }

        return $locked;
    }

    /** Units of a prize not yet tied to a delivery (failed deliveries release theirs). */
    private function remainingQuantity(Prize $prize): int
    {
        $used = PrizeDelivery::query()
            ->where('prize_id', $prize->id)
            ->where('status', '!=', PrizeDeliveryStatus::Failed)
            ->count();

        return max(0, $prize->quantity - $used);
    }

    /**
     * Server-only delivery progression. Reaching `delivered` also
     * drives the winner to `delivered` -> `completed`, and the
     * competition `drawn` -> `completed` — see
     * docs/WINNER_CLAIM_P10.md "Competition completion" for why this is
     * the ONLY point that edge is ever taken (never automatically right
     * after the draw).
     *
     * @throws WinnerException
     */
    public function advance(PrizeDelivery $delivery, PrizeDeliveryStatus $to, string $reason, ?string $trackingReference = null, ?string $deliveryMethod = null, ?int $actorId = null): PrizeDelivery
    {
        return DB::transaction(function () use ($delivery, $to, $reason, $trackingReference, $deliveryMethod, $actorId) {
            /** @var PrizeDelivery $locked */
            $locked = PrizeDelivery::query()->lockForUpdate()->findOrFail($delivery->id);
            $from = $locked->status;

            if (! in_array($to, self::MATRIX[$from->value] ?? [], true)) {
                throw new WinnerException("Cannot move delivery {$locked->id} from \"{$from->value}\" to \"{$to->value}\".");
            }

            $update = ['status' => $to];
            if ($trackingReference !== null) {
                $update['tracking_reference'] = $trackingReference;
            }
            if ($deliveryMethod !== null) {
                $update['delivery_method'] = $deliveryMethod;
            }
            if ($to === PrizeDeliveryStatus::Shipped) {
                $update['shipped_at'] = now();
            }
            if ($to === PrizeDeliveryStatus::Delivered) {
                $update['delivered_at'] = now();
            }
            $locked->update($update);

            WinnerAuditLog::create([
                'winner_id' => $locked->winner_id,
                'event' => match ($to) {
                    PrizeDeliveryStatus::Shipped => 'prize_shipped',
                    PrizeDeliveryStatus::Delivered => 'prize_delivered',
                    PrizeDeliveryStatus::Failed => 'delivery_failed',
                    default => 'delivery_status_changed',
                },
                'from_status' => $from->value,
                'to_status' => $to->value,
                'actor_type' => 'operator',
                'actor_id' => $actorId,
                'metadata' => ['reason' => $reason],
            ]);

            if ($to === PrizeDeliveryStatus::Delivered) {
                $this->completeWinner($locked, $actorId);
            }

            return $locked->fresh();
        });
    }

    /**
     * delivered -> completed on the winner, then (only now) drawn ->
     * completed on the competition. Preferred completion rule per
     * docs/WINNER_CLAIM_P10.md: "winner delivered -> winner completed ->
     * competition completed". Both transitions still go through their
     * one owning service each — this method never writes either status
     * column directly.
     */
    private function completeWinner(PrizeDelivery $delivery, ?int $actorId = null): void
    {
        /** @var Winner $winner */
        $winner = $delivery->winner()->lockForUpdate()->firstOrFail();

        if (! $this->winnerLifecycle->transition($winner, WinnerStatus::Delivered, 'prize delivered', actorType: 'operator', actorId: $actorId)) {
            throw new WinnerException("Winner {$winner->id} cannot be marked delivered from \"{$winner->status->value}\".");
        }

        // Completion is refused, not skipped, if it cannot apply: a delivered
        // prize with a winner or competition that never completed would be
        // exactly the partial state this service must never leave behind.
        if (! $this->winnerLifecycle->transition($winner->fresh(), WinnerStatus::Completed, 'prize delivered', actorType: 'system')) {
            throw new WinnerException("Winner {$winner->id} cannot be completed from \"{$winner->fresh()->status->value}\".");
        }

        WinnerAuditLog::create([
            'winner_id' => $winner->id,
            'event' => 'winner_completed',
            'actor_type' => 'system',
        ]);

        if (! $this->competitionLifecycle->transition(
            $winner->competition,
            CompetitionStatus::Completed,
            'winner completed',
        )) {
            throw new WinnerException("Competition {$winner->competition_id} cannot be completed from its current status.");
        }
    }
}
