<?php

namespace App\Services\Winner;

use App\Enums\CompetitionStatus;
use App\Enums\DrawStatus;
use App\Enums\OrderStatus;
use App\Enums\WinnerStatus;
use App\Models\Competition;
use App\Models\DrawResult;
use App\Models\Winner;
use App\Models\WinnerAuditLog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Resolves a winner from P9's immutable draw_results row and creates the
 * `winners` record — see docs/WINNER_CLAIM_P10.md "Draw -> winner
 * handoff" and "Winner validation". This is the ONLY place a `winners`
 * row is ever inserted; App\Jobs\CreateWinnerJob and
 * `php artisan winners:reconcile` both call
 * createFromDrawResult() — never HTTP input, never a second code path.
 *
 * P10 never recalculates the winner: every fact here is read from
 * draw_results/tickets, never chosen or re-derived by this class.
 */
class WinnerService
{
    /**
     * Idempotent and safe to call 0, 1, or many times for the same
     * competition:
     * - a winner already exists -> returns it, no-op.
     * - competition isn't (still) `drawn`, or any of the P9 handoff
     *   facts don't check out -> throws WinnerException; no winner is
     *   created. FAILS SAFELY — never manufactures a winner from
     *   incomplete/inconsistent data.
     *
     * Locks the competition row for the whole check-then-create window,
     * exactly the same "single serialization point" philosophy as
     * DrawService::finalize() (P9) and CompetitionLifecycleService
     * (P8) — see docs/WINNER_CLAIM_P10.md "Concurrency".
     *
     * @throws WinnerException
     */
    public function createFromDrawResult(Competition $competition): Winner
    {
        return DB::transaction(function () use ($competition) {
            /** @var Competition $locked */
            $locked = Competition::query()->lockForUpdate()->findOrFail($competition->id);

            $existing = Winner::query()->where('competition_id', $locked->id)->first();
            if ($existing !== null) {
                return $existing;
            }

            $this->assertConsistent($locked);

            $drawResult = DrawResult::query()->where('competition_id', $locked->id)->first();
            // assertConsistent() above guarantees this is non-null; re-fetched
            // here (rather than threaded through) to keep validation and
            // creation each reading their own fresh query.
            $ticket = $drawResult->ticket;

            try {
                $winner = Winner::create([
                    'competition_id' => $locked->id,
                    'draw_id' => $drawResult->draw_id,
                    'draw_result_id' => $drawResult->id,
                    'ticket_id' => $ticket->id,
                    'user_id' => $ticket->user_id,
                    'status' => WinnerStatus::PendingContact,
                ]);
            } catch (QueryException $e) {
                // Unique-constraint race lost despite the row lock above
                // (should not be reachable while holding it — defensive
                // only, same posture as DrawService::finalize()). Treat
                // as "someone else already created it".
                $again = Winner::query()->where('competition_id', $locked->id)->first();
                if ($again !== null) {
                    return $again;
                }

                throw $e;
            }

            WinnerAuditLog::create([
                'winner_id' => $winner->id,
                'event' => 'winner_created',
                'from_status' => null,
                'to_status' => WinnerStatus::PendingContact->value,
                'actor_type' => 'system',
                'metadata' => [
                    'draw_id' => $drawResult->draw_id,
                    'draw_result_id' => $drawResult->id,
                    'ticket_number' => $drawResult->ticket_number,
                ],
            ]);

            Log::info('winner.created', [
                'winner_id' => $winner->id,
                'competition_id' => $locked->id,
                'slug' => $locked->slug,
            ]);

            return $winner;
        });
    }

    /**
     * The full pre-creation validation matrix from
     * docs/WINNER_CLAIM_P10.md "Winner validation" (points 1-10). Any
     * failure logs the inconsistency and throws — never creates a
     * partial/guessed winner.
     *
     * @throws WinnerException
     */
    private function assertConsistent(Competition $competition): void
    {
        // 1 & 2: competition exists (guaranteed by the caller's
        // findOrFail) and is drawn.
        if ($competition->status !== CompetitionStatus::Drawn) {
            $this->fail($competition, 'competition is not drawn', ['status' => $competition->status->value]);
        }

        // 5: draw_result exists.
        $drawResult = DrawResult::query()->where('competition_id', $competition->id)->first();
        if ($drawResult === null) {
            $this->fail($competition, 'competition is drawn but has no draw_results row');
        }

        // 3 & 4: draw exists and is completed.
        $draw = $drawResult->draw;
        if ($draw === null || $draw->status !== DrawStatus::Completed) {
            $this->fail($competition, 'draw_result exists but its draw is missing or not completed', [
                'draw_id' => $drawResult->draw_id,
            ]);
        }

        // 6: draw_result belongs to this competition (guaranteed by the
        // query above, asserted defensively).
        if ($drawResult->competition_id !== $competition->id) {
            $this->fail($competition, 'draw_result competition_id mismatch');
        }

        // 7: draw_result's ticket belongs to this competition.
        $ticket = $drawResult->ticket;
        if ($ticket === null || $ticket->competition_id !== $competition->id) {
            $this->fail($competition, 'winning ticket missing or belongs to a different competition', [
                'ticket_id' => $drawResult->ticket_id,
            ]);
        }

        // 8: ticket.user_id exists.
        if ($ticket->user_id === null) {
            $this->fail($competition, 'winning ticket has no user_id', ['ticket_id' => $ticket->id]);
        }

        // 9 & 10: the winning ticket / its order remain eligible per the
        // P9 rule (v1:ticket.order.status=paid) — re-checked live rather
        // than assumed, because PaymentService::markRefunded() can move
        // an order from paid to refunded strictly AFTER its tickets
        // (and, now, a draw result) already exist. P10 must never
        // recalculate the winner, so this is a hard stop, not a
        // re-draw: an already-drawn competition whose winning order was
        // refunded after the draw needs an explicit administrative
        // decision, out of scope for this phase.
        $order = $ticket->order;
        if ($order === null || $order->status !== OrderStatus::Paid) {
            $this->fail($competition, 'winning ticket\'s order is no longer paid', [
                'ticket_id' => $ticket->id,
                'order_status' => $order?->status?->value,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     *
     * @throws WinnerException
     */
    private function fail(Competition $competition, string $reason, array $context = []): never
    {
        Log::error('winner.creation_inconsistent', array_merge([
            'competition_id' => $competition->id,
            'slug' => $competition->slug,
            'reason' => $reason,
        ], $context));

        throw new WinnerException("Cannot create winner for competition {$competition->id}: {$reason}");
    }
}
