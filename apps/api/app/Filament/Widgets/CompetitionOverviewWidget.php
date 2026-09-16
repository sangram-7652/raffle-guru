<?php

namespace App\Filament\Widgets;

use App\Enums\CompetitionStatus;
use App\Filament\Widgets\Concerns\QueriesStatusCounts;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

/**
 * Competition volume, read directly off `competitions.status` — the
 * single source of truth CompetitionLifecycleService already writes
 * (P8). This widget never infers a status from dates; "Active" here
 * means status = active, exactly what the storefront and the lifecycle
 * engine both already mean by it (see Competition::scopePurchasable()).
 *
 * Two queries total:
 *  1. One GROUP BY over `status` (index: competitions_status_index)
 *     for Active/Scheduled/Sold Out.
 *  2. One ranged COUNT for "ending soon" (index:
 *     competitions_status_ends_at_index, a (status, ends_at) composite
 *     that matches this query's WHERE clause exactly).
 */
class CompetitionOverviewWidget extends StatsOverviewWidget
{
    use QueriesStatusCounts;

    protected static ?int $sort = 1;

    protected function getHeading(): ?string
    {
        return 'Competitions';
    }

    protected function getStats(): array
    {
        $counts = $this->statusCounts('competitions');

        // Documented default (P11.4 §3): "ending soon" = still Active
        // and closing within the next 24 hours. Computed once and
        // reused for both bounds of the range, not recalculated.
        $now = Carbon::now();

        $endingSoon = DB::table('competitions')
            ->where('status', CompetitionStatus::Active->value)
            ->whereBetween('ends_at', [$now, $now->clone()->addHours(24)])
            ->count();

        return [
            Stat::make('Active', $counts[CompetitionStatus::Active->value] ?? 0)
                ->color('success'),
            Stat::make('Scheduled', $counts[CompetitionStatus::Scheduled->value] ?? 0)
                ->color('gray'),
            Stat::make('Sold Out', $counts[CompetitionStatus::SoldOut->value] ?? 0)
                ->color('warning'),
            Stat::make('Ending Soon', $endingSoon)
                ->description('Active, closing within 24 hours')
                ->color($endingSoon > 0 ? 'danger' : 'gray'),
        ];
    }
}
