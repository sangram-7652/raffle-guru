<?php

namespace App\Services\Winner;

use App\Models\Competition;
use App\Models\Prize;

/**
 * Resolves which Prize a competition's single winner is entitled to —
 * see docs/WINNER_CLAIM_P10.md "Prize resolution" for the full
 * reasoning. Never accepts a prize_id from a request; a delivery's
 * prize_id is always set by this service or left null.
 *
 * Schema limitation (documented, not guessed around): `prizes` is
 * hasMany from `competitions` (P2), and neither P9's draw_results nor
 * anything else records which specific prize a single draw corresponds
 * to when a competition has more than one `prizes` row. The smallest
 * safe solution: auto-resolve only the unambiguous case (exactly one
 * prize row) and refuse to guess otherwise, leaving `prize_id` null on
 * the delivery for a future administrative assignment (P11 boundary).
 */
class PrizeResolutionService
{
    /**
     * @throws PrizeResolutionException if zero or more than one prize
     *                                  row exists for this competition
     */
    public function resolveForCompetition(Competition $competition): Prize
    {
        $prizes = Prize::query()->where('competition_id', $competition->id)->get();

        if ($prizes->count() === 1) {
            return $prizes->first();
        }

        if ($prizes->count() === 0) {
            throw new PrizeResolutionException("Competition {$competition->id} has no prize to resolve.");
        }

        throw new PrizeResolutionException(
            "Competition {$competition->id} has {$prizes->count()} prizes — cannot automatically resolve which one belongs to the single winner. Requires manual assignment."
        );
    }
}
