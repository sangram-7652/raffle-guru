<?php

namespace App\Jobs;

use App\Models\Competition;
use App\Services\Winner\WinnerException;
use App\Services\Winner\WinnerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Idempotent by design — see App\Services\Winner\WinnerService, the
 * single entry point this job (and `winners:reconcile`) both call.
 * Running this job 0, 1, or many times for the same competition always
 * converges to at most one winner. Mirrors App\Jobs\DrawCompetitionJob
 * (P9) exactly.
 */
class CreateWinnerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Matches the queue worker's --tries=3 convention (see
     * App\Jobs\DrawCompetitionJob).
     */
    public int $tries = 3;

    public function __construct(
        public readonly Competition $competition,
    ) {}

    public function handle(WinnerService $winners): void
    {
        try {
            $winners->createFromDrawResult($this->competition);
        } catch (WinnerException $e) {
            // Deterministic domain failure (inconsistent P9 handoff
            // state) — already logged by WinnerService itself.
            // Retrying changes nothing until an operator intervenes, so
            // fail permanently instead of burning through attempts.
            $this->fail($e);
        }
        // Any other (unexpected/transient) exception propagates and
        // lets Laravel's normal --tries retry/backoff handle it.
    }
}
