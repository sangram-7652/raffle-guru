<?php

namespace App\Filament\Widgets;

use App\Enums\WinnerClaimStatus;
use App\Filament\Widgets\Concerns\QueriesStatusCounts;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * One GROUP BY over `winner_claims.status` (index:
 * winner_claims_status_index, added this milestone). "Awaiting
 * Verification" is Submitted + VerificationPending combined — matching
 * P11.4 §7's literal 4-card list (Awaiting verification / Verified /
 * Approved / Rejected), which names only 4 buckets for a 5-case enum.
 * WinnerClaimService's actual matrix (Submitted -> VerificationPending
 * -> Verified -> {Approved, Rejected}) makes that a natural rollup: both
 * are "not yet verified", not two operationally distinct queue states.
 * Claim mutations (verify/approve/reject) are not implemented here —
 * that's the later Winner Claims resource milestone.
 */
class ClaimOverviewWidget extends StatsOverviewWidget
{
    use QueriesStatusCounts;

    protected static ?int $sort = 5;

    protected function getHeading(): ?string
    {
        return 'Winner Claims';
    }

    protected function getStats(): array
    {
        $counts = $this->statusCounts('winner_claims');

        $awaitingVerification =
            ($counts[WinnerClaimStatus::Submitted->value] ?? 0) +
            ($counts[WinnerClaimStatus::VerificationPending->value] ?? 0);

        return [
            Stat::make('Awaiting Verification', $awaitingVerification)
                ->color($awaitingVerification > 0 ? 'warning' : 'gray'),
            Stat::make('Verified', $counts[WinnerClaimStatus::Verified->value] ?? 0)
                ->color('success'),
            Stat::make('Approved', $counts[WinnerClaimStatus::Approved->value] ?? 0)
                ->color('success'),
            Stat::make('Rejected', $counts[WinnerClaimStatus::Rejected->value] ?? 0)
                ->color('danger'),
        ];
    }
}
