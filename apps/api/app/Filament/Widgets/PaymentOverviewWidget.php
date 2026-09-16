<?php

namespace App\Filament\Widgets;

use App\Enums\PaymentStatus;
use App\Filament\Widgets\Concerns\QueriesStatusCounts;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * One GROUP BY over `payments.status` (index: payments_status_index).
 * Counts only — no gateway IDs, webhook payloads, or gateway secrets
 * are queried or displayed here (P11.4 §5). Read-only, same as every
 * other widget: no Mark Paid/Failed/Refund action exists or will be
 * added from a dashboard widget — that stays PaymentService's alone.
 */
class PaymentOverviewWidget extends StatsOverviewWidget
{
    use QueriesStatusCounts;

    protected static ?int $sort = 3;

    protected function getHeading(): ?string
    {
        return 'Payments';
    }

    protected function getStats(): array
    {
        $counts = $this->statusCounts('payments');

        return [
            Stat::make('Created', $counts[PaymentStatus::Created->value] ?? 0)
                ->description('Awaiting completion')
                ->color('gray'),
            Stat::make('Paid', $counts[PaymentStatus::Paid->value] ?? 0)
                ->color('success'),
            Stat::make('Failed', $counts[PaymentStatus::Failed->value] ?? 0)
                ->color('danger'),
            Stat::make('Refunded', $counts[PaymentStatus::Refunded->value] ?? 0)
                ->color('warning'),
        ];
    }
}
