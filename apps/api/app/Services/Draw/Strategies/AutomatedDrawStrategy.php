<?php

namespace App\Services\Draw\Strategies;

use App\Enums\DrawType;
use App\Models\Competition;
use App\Models\Draw;
use App\Services\Draw\DrawEligibilityService;
use App\Services\Draw\DrawRandomnessService;
use App\Services\Draw\DrawSelection;
use App\Services\Draw\NoEligibleTicketsException;
use Illuminate\Support\Facades\DB;

/**
 * P9's only implemented draw mechanism: no host, no live video, no
 * instant-win — a single server-side CSPRNG selection over the
 * competition's paid-ticket pool. See docs/DRAW_ENGINE_P9.md.
 *
 * Deliberately thin: this class only wires DrawEligibilityService
 * (snapshot + eligibility predicate) to DrawRandomnessService (the
 * actual selection algorithm) inside one consistent-read transaction.
 * It contains no RNG code, no persistence code, and no locking code
 * itself — see docs/DRAW_ENGINE_P9.md "Architecture" for why
 * orchestration/eligibility/RNG/persistence are kept as separate
 * classes.
 */
class AutomatedDrawStrategy implements DrawStrategy
{
    public function __construct(
        private readonly DrawEligibilityService $eligibility,
        private readonly DrawRandomnessService $randomness,
    ) {}

    public function type(): DrawType
    {
        return DrawType::Automated;
    }

    /**
     * @throws NoEligibleTicketsException
     */
    public function select(Competition $competition, Draw $draw): DrawSelection
    {
        return DB::transaction(function () use ($competition) {
            $snapshot = $this->eligibility->snapshot($competition);

            if ($snapshot->eligibleCount < 1) {
                throw new NoEligibleTicketsException(
                    "Competition {$competition->id} has no eligible tickets to draw from.",
                );
            }

            $ticket = $this->randomness->selectEligibleTicket($competition, $snapshot);

            return new DrawSelection(
                ticket: $ticket,
                eligibleTicketCount: $snapshot->eligibleCount,
                eligibilityVersion: $snapshot->eligibilityVersion,
                snapshotChecksum: $snapshot->checksum,
                snapshotAt: $snapshot->snapshotAt,
                randomnessMethod: 'csprng:random_int',
            );
        });
    }
}
