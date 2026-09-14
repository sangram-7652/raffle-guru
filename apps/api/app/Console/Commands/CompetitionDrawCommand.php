<?php

namespace App\Console\Commands;

use App\Enums\CompetitionStatus;
use App\Models\Competition;
use App\Services\Draw\DrawException;
use App\Services\Draw\DrawService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Operator-facing entry point into the draw engine (P9) — see
 * docs/DRAW_ENGINE_P9.md "Operational draw command". Calls the exact
 * same DrawService::run() the queued job calls, so there is only ever
 * one code path that actually performs a draw.
 *
 * `php artisan competition:draw {competition}` — draw one competition
 * by id or slug. Requires it to be in draw_pending (fresh start) or
 * draw_in_progress with a stale attempt (recovery); anything else is
 * reported and left untouched.
 *
 * `php artisan competition:draw` (no argument) — batch safety net: every
 * competition currently in draw_pending (should normally already have
 * been picked up by the CompetitionDrawPending -> DrawCompetitionJob
 * queue handoff; this catches anything the queue missed) plus recovery
 * of any competition in draw_in_progress with a stale attempt. Chunked
 * and non-halting, same pattern as CompetitionLifecycleCommand (P8).
 */
class CompetitionDrawCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'competition:draw {competition? : Competition ID or slug}';

    /**
     * @var string
     */
    protected $description = 'Run the draw for one competition (draw_pending, or a stale draw_in_progress attempt), or sweep all eligible competitions if none is given.';

    public function handle(DrawService $draws): int
    {
        $identifier = $this->argument('competition');

        if ($identifier !== null) {
            return $this->drawOne($draws, $identifier);
        }

        return $this->sweep($draws);
    }

    private function drawOne(DrawService $draws, string $identifier): int
    {
        $competition = ctype_digit($identifier)
            ? Competition::query()->find((int) $identifier)
            : Competition::query()->where('slug', $identifier)->first();

        if ($competition === null) {
            $this->error("No competition found matching \"{$identifier}\".");

            return self::FAILURE;
        }

        if (! in_array($competition->status, [CompetitionStatus::DrawPending, CompetitionStatus::DrawInProgress], true)) {
            $this->warn("Competition {$competition->id} ({$competition->slug}) is \"{$competition->status->value}\" — not eligible to draw.");

            return self::SUCCESS;
        }

        return $this->attempt($draws, $competition);
    }

    private function sweep(DrawService $draws): int
    {
        $processed = 0;

        Competition::query()
            ->whereIn('status', [CompetitionStatus::DrawPending, CompetitionStatus::DrawInProgress])
            ->chunkById(100, function ($competitions) use ($draws, &$processed) {
                foreach ($competitions as $competition) {
                    $this->attempt($draws, $competition);
                    $processed++;
                }
            });

        $this->info("Checked {$processed} competition(s).");

        return self::SUCCESS;
    }

    private function attempt(DrawService $draws, Competition $competition): int
    {
        try {
            $draw = $draws->run($competition);
        } catch (DrawException $e) {
            $this->error("Competition {$competition->id} ({$competition->slug}): draw failed — {$e->getMessage()}");

            return self::FAILURE;
        } catch (\Throwable $e) {
            Log::error('competition.draw.command_failed', [
                'competition_id' => $competition->id,
                'slug' => $competition->slug,
                'error' => $e->getMessage(),
            ]);
            $this->error("Competition {$competition->id} ({$competition->slug}): unexpected error — {$e->getMessage()}");

            return self::FAILURE;
        }

        if ($draw === null) {
            $this->line("Competition {$competition->id} ({$competition->slug}): nothing to do (already in progress elsewhere, or not eligible).");

            return self::SUCCESS;
        }

        $ticketNumber = $draw->result?->ticket_number;
        $this->info("Competition {$competition->id} ({$competition->slug}): drawn — winning ticket #{$ticketNumber} (eligible pool: {$draw->eligible_ticket_count}).");

        return self::SUCCESS;
    }
}
