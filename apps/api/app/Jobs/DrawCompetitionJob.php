<?php

namespace App\Jobs;

use App\Models\Competition;
use App\Services\Draw\DrawException;
use App\Services\Draw\DrawService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Idempotent by design — see App\Services\Draw\DrawService::run(),
 * which is the single entry point this job (and the artisan command)
 * both call. Running this job 0, 1, or many times for the same
 * competition always converges to at most one winner. Mirrors
 * App\Jobs\AllocateTicketsForOrder (P7) exactly.
 */
class DrawCompetitionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Matches the queue worker's own --tries=3 (see
     * infrastructure/docker-compose.yml), same convention as
     * AllocateTicketsForOrder.
     */
    public int $tries = 3;

    public function __construct(
        public readonly Competition $competition,
    ) {}

    public function handle(DrawService $draws): void
    {
        try {
            $draws->run($this->competition);
        } catch (DrawException $e) {
            // Deterministic domain failure (e.g. no eligible tickets) —
            // already recorded on the Draw/DrawAuditLog rows by
            // DrawService itself. Retrying changes nothing, so fail
            // permanently instead of burning through attempts. See
            // App\Jobs\AllocateTicketsForOrder for the identical
            // reasoning in P7.
            $this->fail($e);
        }
        // Any other (unexpected/transient) exception propagates and
        // lets Laravel's normal --tries retry/backoff handle it; the
        // competition safely stays draw_in_progress in the meantime —
        // see docs/DRAW_ENGINE_P9.md "Crash recovery".
    }
}
