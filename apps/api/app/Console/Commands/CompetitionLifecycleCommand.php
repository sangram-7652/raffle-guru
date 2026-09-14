<?php

namespace App\Console\Commands;

use App\Enums\CompetitionStatus;
use App\Models\Competition;
use App\Services\Competitions\CompetitionLifecycleService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Evaluates and applies every time-based lifecycle transition (P8) — see
 * docs/COMPETITION_LIFECYCLE_P8.md. Capacity-based sold_out is handled
 * separately, inline, by TicketAllocationService (not here).
 *
 * Idempotent and safe to run repeatedly or concurrently: each candidate
 * row is only ever mutated through
 * CompetitionLifecycleService::transition(), which locks the row,
 * re-checks its current status, and no-ops rather than erroring if it's
 * no longer eligible (already transitioned by this or another run).
 */
class CompetitionLifecycleCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'competition:lifecycle';

    /**
     * @var string
     */
    protected $description = 'Apply time-based competition lifecycle transitions (activation, closing, draw-readiness). Safe to run repeatedly or concurrently.';

    public function handle(CompetitionLifecycleService $lifecycle): int
    {
        $now = Carbon::now();

        $this->activateScheduled($lifecycle, $now);
        $this->closeExpiredActive($lifecycle, $now);
        $this->closeSoldOut($lifecycle);
        $this->prepareForDraw($lifecycle, $now);

        return self::SUCCESS;
    }

    private function activateScheduled(CompetitionLifecycleService $lifecycle, Carbon $now): void
    {
        Competition::query()
            ->where('status', CompetitionStatus::Scheduled)
            ->where('starts_at', '<=', $now)
            ->chunkById(100, function ($competitions) use ($lifecycle) {
                foreach ($competitions as $competition) {
                    $this->attempt($lifecycle, $competition, CompetitionStatus::Active, 'starts_at reached');
                }
            });
    }

    private function closeExpiredActive(CompetitionLifecycleService $lifecycle, Carbon $now): void
    {
        Competition::query()
            ->where('status', CompetitionStatus::Active)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', $now)
            ->chunkById(100, function ($competitions) use ($lifecycle) {
                foreach ($competitions as $competition) {
                    $this->attempt($lifecycle, $competition, CompetitionStatus::Closed, 'ends_at reached');
                }
            });
    }

    private function closeSoldOut(CompetitionLifecycleService $lifecycle): void
    {
        // Unconditional — see docs/COMPETITION_LIFECYCLE_P8.md "Why
        // sold_out -> closed is unconditional".
        Competition::query()
            ->where('status', CompetitionStatus::SoldOut)
            ->chunkById(100, function ($competitions) use ($lifecycle) {
                foreach ($competitions as $competition) {
                    $this->attempt($lifecycle, $competition, CompetitionStatus::Closed, 'sold out, entry window over');
                }
            });
    }

    private function prepareForDraw(CompetitionLifecycleService $lifecycle, Carbon $now): void
    {
        Competition::query()
            ->where('status', CompetitionStatus::Closed)
            ->whereNotNull('draw_at')
            ->where('draw_at', '<=', $now)
            ->chunkById(100, function ($competitions) use ($lifecycle) {
                foreach ($competitions as $competition) {
                    $this->attempt($lifecycle, $competition, CompetitionStatus::DrawPending, 'draw_at reached');
                }
            });
    }

    /**
     * One malformed/failing competition must not halt the rest of the
     * batch.
     */
    private function attempt(
        CompetitionLifecycleService $lifecycle,
        Competition $competition,
        CompetitionStatus $to,
        string $reason,
    ): void {
        try {
            $transitioned = $lifecycle->transition($competition, $to, $reason);

            if ($transitioned) {
                $this->info("Competition {$competition->id} ({$competition->slug}): -> {$to->value} ({$reason})");
            }
        } catch (\Throwable $e) {
            Log::error('competition.lifecycle.command_failed', [
                'competition_id' => $competition->id,
                'slug' => $competition->slug,
                'current_status' => $competition->status?->value,
                'target_status' => $to->value,
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
