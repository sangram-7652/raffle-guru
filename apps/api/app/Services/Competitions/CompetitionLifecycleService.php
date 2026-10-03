<?php

namespace App\Services\Competitions;

use App\Enums\CompetitionStatus;
use App\Events\CompetitionDrawn;
use App\Events\CompetitionDrawPending;
use App\Models\Competition;
use App\Models\CompetitionLifecycleEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The only place `competitions.status` is ever written. See
 * docs/COMPETITION_LIFECYCLE_P8.md for P8's part of the matrix and
 * docs/DRAW_ENGINE_P9.md for P9's addition (draw_pending ->
 * draw_in_progress -> drawn) — this remains the single owner for both
 * phases rather than P9 inventing a second lifecycle-writing mechanism.
 */
class CompetitionLifecycleService
{
    /**
     * Every allowed (from, to) pair. Anything not listed here —
     * including any path to `completed` (P10's job) or any backward
     * transition — is rejected.
     *
     * @var array<string, list<CompetitionStatus>>
     */
    private const MATRIX = [
        // P11.9 — the only admin-initiated way out of draft: an editorial
        // publish that schedules the competition (not yet public). Public
        // visibility still only comes from scheduled -> active, either at
        // starts_at (CompetitionLifecycleCommand) or by an admin action.
        CompetitionStatus::Draft->value => [CompetitionStatus::Scheduled],
        CompetitionStatus::Scheduled->value => [CompetitionStatus::Active],
        CompetitionStatus::Active->value => [CompetitionStatus::SoldOut, CompetitionStatus::Closed],
        CompetitionStatus::SoldOut->value => [CompetitionStatus::Closed],
        CompetitionStatus::Closed->value => [CompetitionStatus::DrawPending],
        // P9 additions — see docs/DRAW_ENGINE_P9.md "Draw lifecycle".
        // Note there is deliberately no entry for DrawInProgress ->
        // DrawPending (a stale/crashed attempt is recovered by starting
        // a NEW `draws` attempt row while the competition stays
        // draw_in_progress, never by moving the competition backwards —
        // see App\Services\Draw\DrawService) and no entry for Drawn at
        // all (drawn -> completed is explicitly P10's).
        CompetitionStatus::DrawPending->value => [CompetitionStatus::DrawInProgress],
        CompetitionStatus::DrawInProgress->value => [CompetitionStatus::Drawn],
        // P10 addition — see docs/WINNER_CLAIM_P10.md "Competition
        // completion". Reached only via
        // App\Services\Winner\PrizeFulfilmentService once the winner
        // itself has reached WinnerStatus::Delivered ->
        // WinnerStatus::Completed; never fired automatically right
        // after the draw. No other edge into/out of Completed exists —
        // active/closed/draw_pending/draw_in_progress -> completed all
        // stay rejected.
        CompetitionStatus::Drawn->value => [CompetitionStatus::Completed],
    ];

    public function canTransition(CompetitionStatus $from, CompetitionStatus $to): bool
    {
        return in_array($to, self::MATRIX[$from->value] ?? [], true);
    }

    /**
     * Locks the competition row, re-reads its CURRENT status (never
     * trusting whatever the caller's $competition instance happens to
     * hold), and applies the transition only if still valid against the
     * matrix. Returns false — a safe no-op, never an exception — for
     * both "invalid transition" and "already transitioned by someone
     * else": those look identical from here, which is exactly what
     * makes calling this repeatedly (the lifecycle command re-running,
     * two workers racing) idempotent.
     *
     * Safe to call from inside an already-open transaction (e.g.
     * TicketAllocationService, which already holds this same row's
     * lock) — MySQL/InnoDB re-locking a row your own connection already
     * holds is a harmless no-op, and DB::transaction() uses a savepoint
     * for the nested call.
     */
    public function transition(Competition $competition, CompetitionStatus $to, string $reason): bool
    {
        return DB::transaction(function () use ($competition, $to, $reason) {
            /** @var Competition $locked */
            $locked = Competition::query()->lockForUpdate()->findOrFail($competition->id);
            $from = $locked->status;

            if (! $this->canTransition($from, $to)) {
                return false;
            }

            $locked->update(['status' => $to]);

            CompetitionLifecycleEvent::create([
                'competition_id' => $locked->id,
                'from_status' => $from->value,
                'to_status' => $to->value,
                'reason' => $reason,
            ]);

            Log::info('competition.lifecycle.transitioned', [
                'competition_id' => $locked->id,
                'slug' => $locked->slug,
                'from' => $from->value,
                'to' => $to->value,
                'reason' => $reason,
            ]);

            // P8 -> P9 handoff, mirroring exactly how PaymentService
            // fires OrderPaid for the P6 -> P7 handoff: fired only on
            // the call that actually performs this specific transition
            // (never on an idempotent no-op), and only once it has
            // genuinely, durably committed. CompetitionLifecycleService
            // stays unaware of anything about how draws work beyond
            // "an event exists" — see docs/DRAW_ENGINE_P9.md "Trigger".
            if ($to === CompetitionStatus::DrawPending) {
                DB::afterCommit(fn () => CompetitionDrawPending::dispatch($locked->fresh()));
            }

            // P9 -> P10 handoff, same reasoning as above — see
            // docs/WINNER_CLAIM_P10.md "Draw -> winner handoff". Fired
            // only on the call that actually performs this specific
            // transition, only once it has durably committed.
            // CompetitionLifecycleService stays unaware of anything
            // about winners beyond "an event exists".
            if ($to === CompetitionStatus::Drawn) {
                DB::afterCommit(fn () => CompetitionDrawn::dispatch($locked->fresh()));
            }

            return true;
        });
    }
}
