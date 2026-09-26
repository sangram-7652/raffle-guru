<?php

namespace App\Filament\Support;

use Filament\Tables\Filters\Filter;

/**
 * DateRangeFilter on the table's own `created_at`.
 */
final class CreatedBetweenFilter
{
    public static function make(): Filter
    {
        return DateRangeFilter::make('created_between', 'created_at', 'Created');
    }
}
