<?php

namespace App\Filament\Widgets;

use App\Enums\PrizeDeliveryStatus;
use App\Filament\Widgets\Concerns\QueriesStatusCounts;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * One GROUP BY over `prize_deliveries.status` (index:
 * prize_deliveries_status_index, added this milestone). All 5
 * PrizeDeliveryStatus cases, matching P11.4 §8's list exactly. No
 * tracking numbers, courier integration, or delivery mutation here —
 * this table tracks manual fulfilment only (no courier integration
 * exists in this phase); actions belong to PrizeFulfilmentService via
 * a later resource milestone.
 */
class FulfilmentOverviewWidget extends StatsOverviewWidget
{
    use QueriesStatusCounts;

    protected static ?int $sort = 6;

    protected function getHeading(): ?string
    {
        return 'Prize Fulfilment';
    }

    protected function getStats(): array
    {
        $counts = $this->statusCounts('prize_deliveries');

        return [
            Stat::make('Pending', $counts[PrizeDeliveryStatus::Pending->value] ?? 0)
                ->color('warning'),
            Stat::make('Processing', $counts[PrizeDeliveryStatus::Processing->value] ?? 0)
                ->color('gray'),
            Stat::make('Shipped', $counts[PrizeDeliveryStatus::Shipped->value] ?? 0)
                ->color('gray'),
            Stat::make('Delivered', $counts[PrizeDeliveryStatus::Delivered->value] ?? 0)
                ->color('success'),
            Stat::make('Failed', $counts[PrizeDeliveryStatus::Failed->value] ?? 0)
                ->color('danger'),
        ];
    }
}
