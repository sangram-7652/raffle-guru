<?php

namespace App\Services\Winner;

use App\Enums\CompetitionStatus;
use App\Enums\PrizeDeliveryStatus;
use App\Enums\WinnerStatus;
use App\Models\PrizeDelivery;
use App\Models\Winner;
use App\Models\WinnerAuditLog;
use App\Services\Competitions\CompetitionLifecycleService;
use Illuminate\Database\QueryException;
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
    public function markDeliveryPending(Winner $winner, string $reason = 'claim approved'): bool
    {
        return $this->winnerLifecycle->transition($winner, WinnerStatus::DeliveryPending, $reason, actorType: 'operator');
    }

    /**
     * Creates the (at most one, ever) PrizeDelivery for a winner already
     * `delivery_pending`. Locks the winner row for the whole
     * check-then-create window — same philosophy as
     * WinnerService::createFromDrawResult() — with
     * `prize_deliveries.winner_id`'s UNIQUE constraint as the hard
     * database backstop. See docs/WINNER_CLAIM_P10.md "Concurrency" (E).
     *
     * If the competition's prize cannot be unambiguously resolved (see
     * PrizeResolutionService), this throws and creates nothing — the
     * winner stays `delivery_pending`, safe to retry once a prize can
     * be resolved (documented P11 admin-assignment gap).
     *
     * @throws PrizeResolutionException|WinnerException
     */
    public function createDelivery(Winner $winner): PrizeDelivery
    {
        return DB::transaction(function () use ($winner) {
            /** @var Winner $locked */
            $locked = Winner::query()->lockForUpdate()->findOrFail($winner->id);

            if ($locked->status !== WinnerStatus::DeliveryPending) {
                throw new WinnerException("Cannot create a delivery for winner {$locked->id}: status is \"{$locked->status->value}\", expected \"delivery_pending\".");
            }

            $existing = PrizeDelivery::query()->where('winner_id', $locked->id)->first();
            if ($existing !== null) {
                return $existing;
            }

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
                'metadata' => ['prize_id' => $prize->id, 'delivery_id' => $delivery->id],
            ]);

            return $delivery;
        });
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
    public function advance(PrizeDelivery $delivery, PrizeDeliveryStatus $to, string $reason, ?string $trackingReference = null, ?string $deliveryMethod = null): PrizeDelivery
    {
        return DB::transaction(function () use ($delivery, $to, $reason, $trackingReference, $deliveryMethod) {
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
                'metadata' => ['reason' => $reason],
            ]);

            if ($to === PrizeDeliveryStatus::Delivered) {
                $this->completeWinner($locked);
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
    private function completeWinner(PrizeDelivery $delivery): void
    {
        /** @var Winner $winner */
        $winner = $delivery->winner()->lockForUpdate()->firstOrFail();

        $this->winnerLifecycle->transition($winner, WinnerStatus::Delivered, 'prize delivered', actorType: 'operator');

        $delivered = $this->winnerLifecycle->transition($winner->fresh(), WinnerStatus::Completed, 'prize delivered', actorType: 'system');

        if ($delivered) {
            WinnerAuditLog::create([
                'winner_id' => $winner->id,
                'event' => 'winner_completed',
                'actor_type' => 'system',
            ]);

            $this->competitionLifecycle->transition(
                $winner->competition,
                CompetitionStatus::Completed,
                'winner completed',
            );
        }
    }
}
