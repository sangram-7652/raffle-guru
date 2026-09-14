<?php

namespace App\Services\Draw;

use App\Enums\CompetitionStatus;
use App\Enums\DrawStatus;
use App\Models\Competition;
use App\Models\Draw;
use App\Models\DrawAuditLog;
use App\Models\DrawResult;
use App\Services\Competitions\CompetitionLifecycleService;
use App\Services\Draw\Strategies\DrawStrategy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The single orchestrator for running a competition's draw — see
 * docs/DRAW_ENGINE_P9.md. Owns locking, attempt bookkeeping, crash
 * recovery, and finalization; delegates "how to pick a winner" entirely
 * to a DrawStrategy (App\Services\Draw\Strategies), and "how to read
 * eligibility" to DrawEligibilityService. This class never runs RNG
 * code and never queries ticket eligibility directly.
 *
 * `competitions.status` is still ONLY ever written by
 * CompetitionLifecycleService (see that class) — this service calls
 * into it rather than writing the column itself, so P9 does not
 * introduce a second lifecycle-writing mechanism.
 */
class DrawService
{
    /**
     * How long a `running` attempt may go without completing before a
     * later call is allowed to treat it as crashed and recover it. A
     * generous bound: this phase's selection algorithm is expected to
     * finish in well under a second even for a million-ticket
     * competition (see docs/DRAW_ENGINE_P9.md "Performance"), so
     * anything still `running` after 15 minutes is overwhelmingly
     * more likely to be a dead worker than a slow one.
     */
    private const STALE_LEASE_SECONDS = 900;

    public function __construct(
        private readonly CompetitionLifecycleService $lifecycle,
        private readonly DrawStrategy $strategy,
    ) {}

    /**
     * The single entry point used by both the queued job and the
     * artisan command. Idempotent and safe to call 0, 1, or many times
     * for the same competition:
     * - not in draw_pending or draw_in_progress -> null, no-op.
     * - in draw_pending -> acquires it (draw_pending -> draw_in_progress)
     *   and runs a fresh attempt.
     * - in draw_in_progress with a live (non-stale) running attempt ->
     *   null, no-op (don't steal work from another in-flight worker).
     * - in draw_in_progress with a stale running attempt -> recovers it
     *   (marks it failed, starts a new attempt) and runs the new one.
     *
     * @throws DrawException on a genuine selection failure (the caller
     *                        decides how to treat that — see
     *                        App\Jobs\DrawCompetitionJob).
     */
    public function run(Competition $competition): ?Draw
    {
        $draw = $this->acquireOrRecover($competition);

        if ($draw === null) {
            return null;
        }

        try {
            $selection = $this->strategy->select($competition->fresh(), $draw);
        } catch (\Throwable $e) {
            $this->markFailed($draw, $e);
            throw $e;
        }

        $this->finalize($competition, $draw, $selection);

        return $draw->fresh();
    }

    /**
     * Locks the competition row for the shortest possible window —
     * just the status check, the (possible) status transition, and
     * creating/updating small `draws`/`draw_audit_logs` rows. The
     * expensive eligibility snapshot + ticket selection happen AFTER
     * this method returns and releases the lock, per
     * docs/DRAW_ENGINE_P9.md "Draw locking".
     */
    private function acquireOrRecover(Competition $competition): ?Draw
    {
        return DB::transaction(function () use ($competition) {
            /** @var Competition $locked */
            $locked = Competition::query()->lockForUpdate()->findOrFail($competition->id);

            if ($locked->status === CompetitionStatus::DrawPending) {
                $transitioned = $this->lifecycle->transition(
                    $locked,
                    CompetitionStatus::DrawInProgress,
                    'draw acquired',
                );

                if (! $transitioned) {
                    // Can't actually happen while we hold the row lock
                    // (nobody else can have changed the status between
                    // our read above and now) — defensive only.
                    return null;
                }

                $draw = Draw::create([
                    'competition_id' => $locked->id,
                    'type' => $this->strategy->type(),
                    'status' => DrawStatus::Running,
                    'attempt' => 1,
                    'started_at' => now(),
                ]);

                $this->audit($draw, $locked, 'draw.started', ['attempt' => 1]);

                return $draw;
            }

            if ($locked->status === CompetitionStatus::DrawInProgress) {
                if (DrawResult::query()->where('competition_id', $locked->id)->exists()) {
                    // Already genuinely drawn — defensive; competition
                    // status should already be Drawn in this case.
                    return null;
                }

                /** @var Draw|null $runningAttempt */
                $runningAttempt = Draw::query()
                    ->where('competition_id', $locked->id)
                    ->where('status', DrawStatus::Running)
                    ->orderByDesc('attempt')
                    ->first();

                if ($runningAttempt === null) {
                    Log::warning('draw.inconsistent_state', [
                        'competition_id' => $locked->id,
                        'reason' => 'draw_in_progress with no running attempt row',
                    ]);

                    return null;
                }

                if ($runningAttempt->started_at->gt(now()->subSeconds(self::STALE_LEASE_SECONDS))) {
                    // Still within its lease — another worker is
                    // plausibly still actively running it. Don't steal.
                    return null;
                }

                $runningAttempt->update([
                    'status' => DrawStatus::Failed,
                    'failed_at' => now(),
                    'failure_reason' => 'stale attempt exceeded '.self::STALE_LEASE_SECONDS.'s lease',
                ]);

                $this->audit($runningAttempt, $locked, 'draw.stale_attempt_recovered', [
                    'stale_attempt' => $runningAttempt->attempt,
                    'started_at' => $runningAttempt->started_at->toIso8601String(),
                ]);

                $draw = Draw::create([
                    'competition_id' => $locked->id,
                    'type' => $this->strategy->type(),
                    'status' => DrawStatus::Running,
                    'attempt' => $runningAttempt->attempt + 1,
                    'started_at' => now(),
                ]);

                $this->audit($draw, $locked, 'draw.started', [
                    'attempt' => $draw->attempt,
                    'recovered_from_draw_id' => $runningAttempt->id,
                ]);

                return $draw;
            }

            return null;
        });
    }

    private function markFailed(Draw $draw, \Throwable $e): void
    {
        $draw->update([
            'status' => DrawStatus::Failed,
            'failed_at' => now(),
            'failure_reason' => substr($e->getMessage(), 0, 255),
        ]);

        $this->audit($draw, null, 'draw.failed', [
            'exception' => $e::class,
            'message' => substr($e->getMessage(), 0, 500),
        ]);

        Log::error('draw.execution_failed', [
            'draw_id' => $draw->id,
            'competition_id' => $draw->competition_id,
            'attempt' => $draw->attempt,
            'exception' => $e::class,
        ]);
    }

    /**
     * Reacquires the competition lock to finalize atomically:
     * competition status + DrawResult + the draw row's own completion
     * fields all commit together, or none do. See
     * docs/DRAW_ENGINE_P9.md "Transactions".
     */
    private function finalize(Competition $competition, Draw $draw, DrawSelection $selection): void
    {
        DB::transaction(function () use ($competition, $draw, $selection) {
            /** @var Competition $locked */
            $locked = Competition::query()->lockForUpdate()->findOrFail($competition->id);

            if ($locked->status !== CompetitionStatus::DrawInProgress) {
                // Someone else already finalized (or something is very
                // wrong) — treat as a lost race, not an error. The
                // unique constraint on draw_results.competition_id is
                // the hard backstop below regardless.
                $draw->update([
                    'status' => DrawStatus::Failed,
                    'failed_at' => now(),
                    'failure_reason' => 'competition no longer draw_in_progress at finalization (lost race)',
                ]);

                return;
            }

            if (DrawResult::query()->where('competition_id', $locked->id)->exists()) {
                $draw->update([
                    'status' => DrawStatus::Failed,
                    'failed_at' => now(),
                    'failure_reason' => 'competition already has a draw result (lost race)',
                ]);

                return;
            }

            DrawResult::create([
                'draw_id' => $draw->id,
                'competition_id' => $locked->id,
                'ticket_id' => $selection->ticket->id,
                'ticket_number' => $selection->ticket->ticket_number,
                'eligible_ticket_count' => $selection->eligibleTicketCount,
                'selected_at' => now(),
            ]);

            $this->lifecycle->transition($locked, CompetitionStatus::Drawn, 'draw completed');

            $draw->update([
                'status' => DrawStatus::Completed,
                'completed_at' => now(),
                'snapshot_at' => $selection->snapshotAt,
                'eligible_ticket_count' => $selection->eligibleTicketCount,
                'eligibility_version' => $selection->eligibilityVersion,
                'snapshot_checksum' => $selection->snapshotChecksum,
                'randomness_method' => $selection->randomnessMethod,
            ]);

            $this->audit($draw, $locked, 'draw.completed', [
                'ticket_number' => $selection->ticket->ticket_number,
                'eligible_ticket_count' => $selection->eligibleTicketCount,
                'snapshot_checksum' => $selection->snapshotChecksum,
            ]);

            Log::info('draw.completed', [
                'draw_id' => $draw->id,
                'competition_id' => $locked->id,
                'slug' => $locked->slug,
                'ticket_number' => $selection->ticket->ticket_number,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function audit(Draw $draw, ?Competition $competition, string $event, ?array $metadata = null): void
    {
        DrawAuditLog::create([
            'draw_id' => $draw->id,
            'competition_id' => $competition?->id ?? $draw->competition_id,
            'event' => $event,
            'metadata' => $metadata,
        ]);
    }
}
