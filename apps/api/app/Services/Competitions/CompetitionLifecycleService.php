<?php

namespace App\Services\Competitions;

use App\Enums\CompetitionStatus;
use App\Models\Competition;
use App\Models\CompetitionLifecycleEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The only place `competitions.status` is ever written. See
 * docs/COMPETITION_LIFECYCLE_P8.md for the full design — this class
 * implements exactly the transition matrix documented there.
 */
class CompetitionLifecycleService
{
    /**
     * Every allowed (from, to) pair. Anything not listed here — including
     * every path to draw_in_progress/drawn/completed, which are P9/P10's
     * — is rejected.
     *
     * @var array<string, list<CompetitionStatus>>
     */
    private const MATRIX = [
        CompetitionStatus::Scheduled->value => [CompetitionStatus::Active],
        CompetitionStatus::Active->value => [CompetitionStatus::SoldOut, CompetitionStatus::Closed],
        CompetitionStatus::SoldOut->value => [CompetitionStatus::Closed],
        CompetitionStatus::Closed->value => [CompetitionStatus::DrawPending],
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

            return true;
        });
    }
}
