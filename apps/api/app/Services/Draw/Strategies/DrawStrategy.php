<?php

namespace App\Services\Draw\Strategies;

use App\Enums\DrawType;
use App\Models\Competition;
use App\Models\Draw;
use App\Services\Draw\DrawSelection;

/**
 * The extension point for future draw mechanisms — see
 * docs/DRAW_ENGINE_P9.md "Future draw types". P9 binds exactly one
 * implementation (AutomatedDrawStrategy); LiveDrawStrategy and
 * InstantWinStrategy are NOT implemented here, but adding either later
 * is a new class + a new App\Enums\DrawType case + a binding decision,
 * never a change to DrawService's orchestration (locking, attempt
 * bookkeeping, finalization all stay the same regardless of how a
 * strategy picks its winner).
 */
interface DrawStrategy
{
    public function type(): DrawType;

    /**
     * Selects the winning ticket for this attempt. Must not mutate any
     * status/persist any final result itself — that remains
     * DrawService's job, so every strategy gets the same locking/
     * idempotency/audit guarantees for free.
     *
     * @throws \App\Services\Draw\NoEligibleTicketsException
     */
    public function select(Competition $competition, Draw $draw): DrawSelection;
}
