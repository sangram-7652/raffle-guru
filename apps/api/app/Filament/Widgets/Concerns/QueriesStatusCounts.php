<?php

namespace App\Filament\Widgets\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * One grouped COUNT query per widget instead of one query per status
 * (P11.4 §13) — every status-breakdown widget on the dashboard shares
 * this instead of hand-rolling the same `GROUP BY` shape six times.
 * Deliberately queries the plain query builder (DB::table), not an
 * Eloquent model — this is a pure aggregate, so there's no row to
 * hydrate, and it sidesteps Eloquent's enum attribute casting turning
 * the plucked status value into a non-scalar array key.
 */
trait QueriesStatusCounts
{
    /**
     * @return array<string, int>
     */
    protected function statusCounts(string $table, string $column = 'status'): array
    {
        return DB::table($table)
            ->select($column, DB::raw('COUNT(*) as aggregate'))
            ->groupBy($column)
            ->pluck('aggregate', $column)
            ->map(fn ($count) => (int) $count)
            ->all();
    }
}
