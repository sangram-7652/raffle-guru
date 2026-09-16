<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Filament\Widgets\Concerns\QueriesStatusCounts;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * One GROUP BY query over `orders.status` (index: orders_status_index)
 * — every card below reads from the same single result set. "Total" is
 * a PHP sum over that already-aggregated, ≤8-row array, not a second
 * database round trip. No revenue/monetary total is shown: P11.4 §4
 * only asks for counts, and this codebase has no single established
 * definition of "revenue" (gross order total? paid-only? net of
 * refunds?) to display without risking exactly the misleading metric
 * that section warns against.
 */
class OrderOverviewWidget extends StatsOverviewWidget
{
    use QueriesStatusCounts;

    protected static ?int $sort = 2;

    protected function getHeading(): ?string
    {
        return 'Orders';
    }

    protected function getStats(): array
    {
        $counts = $this->statusCounts('orders');

        return [
            Stat::make('Total', array_sum($counts)),
            Stat::make('Pending', $counts[OrderStatus::Pending->value] ?? 0)
                ->color('gray'),
            Stat::make('Paid', $counts[OrderStatus::Paid->value] ?? 0)
                ->color('success'),
            Stat::make('Failed', $counts[OrderStatus::Failed->value] ?? 0)
                ->color('danger'),
            Stat::make('Cancelled', $counts[OrderStatus::Cancelled->value] ?? 0)
                ->color('gray'),
            Stat::make('Refunded', $counts[OrderStatus::Refunded->value] ?? 0)
                ->color('warning'),
        ];
    }
}
