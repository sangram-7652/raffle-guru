<?php

namespace App\Filament\Widgets;

use App\Enums\WinnerStatus;
use App\Filament\Widgets\Concerns\QueriesStatusCounts;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * One GROUP BY over `winners.status` (index: winners_status_index,
 * added this milestone — the column had no index at all before). Shows
 * every WinnerStatus case rather than the shorter illustrative list in
 * P11.4 §6 ("for example: ...") — Claim Approved and Delivered are
 * real, reachable states an operator needs visibility into, and
 * dropping them silently would lose information the same query already
 * has for free. Status is exclusively written by
 * WinnerLifecycleService; nothing here mutates a Winner.
 */
class WinnerOverviewWidget extends StatsOverviewWidget
{
    use QueriesStatusCounts;

    protected static ?int $sort = 4;

    protected function getHeading(): ?string
    {
        return 'Winners';
    }

    protected function getStats(): array
    {
        $counts = $this->statusCounts('winners');

        return [
            Stat::make('Pending Contact', $counts[WinnerStatus::PendingContact->value] ?? 0)
                ->color('warning'),
            Stat::make('Contacted', $counts[WinnerStatus::Contacted->value] ?? 0)
                ->color('gray'),
            Stat::make('Verification Pending', $counts[WinnerStatus::VerificationPending->value] ?? 0)
                ->color('warning'),
            Stat::make('Verified', $counts[WinnerStatus::Verified->value] ?? 0)
                ->color('success'),
            Stat::make('Claim Approved', $counts[WinnerStatus::ClaimApproved->value] ?? 0)
                ->color('success'),
            Stat::make('Delivery Pending', $counts[WinnerStatus::DeliveryPending->value] ?? 0)
                ->color('warning'),
            Stat::make('Delivered', $counts[WinnerStatus::Delivered->value] ?? 0)
                ->color('success'),
            Stat::make('Completed', $counts[WinnerStatus::Completed->value] ?? 0)
                ->color('success'),
        ];
    }
}
