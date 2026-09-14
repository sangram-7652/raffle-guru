<?php

namespace App\Services\Draw;

use Illuminate\Support\Carbon;

/**
 * Eligibility snapshot metadata for one draw attempt — deliberately
 * NOT a copy of every eligible ticket row. See
 * docs/DRAW_ENGINE_P9.md "Snapshot strategy": the count/range/checksum
 * combination is what makes the snapshot auditable ("exactly which
 * tickets were eligible when this draw happened?" is answerable by
 * re-running the same deterministic eligibility query and comparing
 * count + checksum) without ever duplicating a potentially
 * million-row dataset into this attempt's own storage.
 */
final readonly class DrawSnapshot
{
    public function __construct(
        public int $eligibleCount,
        public int $overallMaxTicketNumber,
        public Carbon $snapshotAt,
        public string $eligibilityVersion,
        public string $checksum,
    ) {}
}
