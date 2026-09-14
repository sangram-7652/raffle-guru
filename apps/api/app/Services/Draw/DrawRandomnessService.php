<?php

namespace App\Services\Draw;

use App\Models\Competition;
use App\Models\Ticket;

/**
 * Selects a single, uniformly random ELIGIBLE ticket without ever
 * loading the competition's ticket set into PHP memory. See
 * docs/DRAW_ENGINE_P9.md "Selection algorithm" for the full reasoning
 * and the uniformity argument; summary:
 *
 * Primary path — rejection sampling by ticket_number: tickets are
 * numbered sequentially per competition starting at 1
 * (docs/TICKET_ENGINE_DESIGN_P7.md), but eligibility can exclude some
 * numbers (a refunded order's tickets), so the eligible set is not
 * necessarily contiguous. Draw a CSPRNG-uniform candidate number in
 * [1, overallMaxTicketNumber], look it up with a single indexed
 * point-query, and accept it if eligible. Rejection sampling from a
 * uniform distribution over a superset, conditioned on membership in a
 * subset, is itself exactly uniform over that subset — this is not an
 * approximation. Each attempt costs one indexed lookup; with the
 * expected (low) refund rate this terminates in O(1) attempts
 * regardless of competition size — 10,000 or 1,000,000 tickets cost the
 * same per-attempt price.
 *
 * Fallback path — only if MAX_REJECTION_ATTEMPTS is exhausted (a
 * pathologically high exclusion rate): pick a uniform index into the
 * eligible set itself and fetch that exact ordinal via
 * DrawEligibilityService::nthEligibleTicket(), which still returns
 * exactly one row to PHP. This path is guaranteed to terminate but is
 * O(offset) work inside MySQL, which is why it is the fallback and not
 * the default.
 *
 * Never uses a non-cryptographic or timestamp-derived randomness
 * source, and never shuffles an array to "randomize" order — only
 * PHP's CSPRNG function. See
 * tests/Feature/Draw/DrawRandomnessTest.php
 * "no_mt_rand_or_rand_is_used_anywhere_in_the_draw_domain".
 */
class DrawRandomnessService
{
    private const MAX_REJECTION_ATTEMPTS = 500;

    public function __construct(
        private readonly DrawEligibilityService $eligibility,
    ) {}

    public function selectEligibleTicket(Competition $competition, DrawSnapshot $snapshot): Ticket
    {
        if ($snapshot->eligibleCount < 1 || $snapshot->overallMaxTicketNumber < 1) {
            throw new NoEligibleTicketsException(
                "Competition {$competition->id} has no eligible tickets to draw from.",
            );
        }

        for ($attempt = 0; $attempt < self::MAX_REJECTION_ATTEMPTS; $attempt++) {
            $candidateNumber = random_int(1, $snapshot->overallMaxTicketNumber);
            $ticket = $this->eligibility->ticketIfEligible($competition->id, $candidateNumber);

            if ($ticket !== null) {
                return $ticket;
            }
        }

        $index = random_int(0, $snapshot->eligibleCount - 1);
        $ticket = $this->eligibility->nthEligibleTicket($competition->id, $index);

        if ($ticket === null) {
            // The eligible set shrank between the snapshot and here
            // (e.g. a refund landed mid-selection) enough to invalidate
            // the offset — vanishingly unlikely and self-correcting: the
            // caller's transaction rolls back and DrawService records a
            // failed attempt that a fresh retry will re-snapshot
            // correctly.
            throw new NoEligibleTicketsException(
                "Eligible ticket set for competition {$competition->id} changed during selection.",
            );
        }

        return $ticket;
    }
}
