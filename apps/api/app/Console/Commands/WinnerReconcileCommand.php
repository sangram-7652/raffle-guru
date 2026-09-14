<?php

namespace App\Console\Commands;

use App\Enums\CompetitionStatus;
use App\Models\Competition;
use App\Services\Winner\WinnerException;
use App\Services\Winner\WinnerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Crash-recovery safety net for the draw -> winner handoff — see
 * docs/WINNER_CLAIM_P10.md "Reconciliation". Covers the case where
 * `draw_results` (and competitions.status = drawn) committed but the
 * worker running CreateWinnerJob crashed before a `winners` row was
 * created.
 *
 * `php artisan winners:reconcile` — every competition currently
 * `drawn` with no winner yet. Calls the exact same
 * WinnerService::createFromDrawResult() the queued job calls, so there
 * is only ever one code path that can create a winner. Never recomputes
 * the draw result, never selects a new ticket, never changes the
 * winning user, never creates a duplicate. Chunked — never loads all
 * drawn competitions into memory at once.
 */
class WinnerReconcileCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'winners:reconcile';

    /**
     * @var string
     */
    protected $description = 'Create any missing winner for a drawn competition (crash-recovery safety net for the draw -> winner handoff).';

    public function handle(WinnerService $winners): int
    {
        $created = 0;
        $skipped = 0;
        $failed = 0;

        Competition::query()
            ->where('status', CompetitionStatus::Drawn)
            ->whereDoesntHave('winner')
            ->chunkById(100, function ($competitions) use ($winners, &$created, &$skipped, &$failed) {
                foreach ($competitions as $competition) {
                    // Re-check per-row: another chunk iteration, the
                    // queue, or a concurrent reconcile run may have just
                    // created it.
                    if ($competition->winner()->exists()) {
                        $skipped++;

                        continue;
                    }

                    try {
                        $winners->createFromDrawResult($competition);
                        $created++;
                    } catch (WinnerException $e) {
                        $failed++;
                        Log::error('winners.reconcile.failed', [
                            'competition_id' => $competition->id,
                            'slug' => $competition->slug,
                            'error' => $e->getMessage(),
                        ]);
                        $this->error("Competition {$competition->id} ({$competition->slug}): {$e->getMessage()}");
                    }
                }
            });

        $this->info("Reconciliation complete: {$created} created, {$skipped} already existed, {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
