<?php

namespace App\Filament\Support;

use Filament\Forms\Components\DatePicker;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * "Created from / until" date-range filter on the table's own
 * `created_at`, applied in SQL. Dates are whole days in the app timezone.
 */
final class CreatedBetweenFilter
{
    public static function make(): Filter
    {
        return Filter::make('created_between')
            ->label('Created')
            ->schema([
                DatePicker::make('from')->label('Created from'),
                DatePicker::make('until')->label('Created until'),
            ])
            ->query(fn (Builder $query, array $data): Builder => $query
                ->when($data['from'] ?? null, fn (Builder $q, string $from): Builder => $q
                    ->where($q->qualifyColumn('created_at'), '>=', Carbon::parse($from)->startOfDay()))
                ->when($data['until'] ?? null, fn (Builder $q, string $until): Builder => $q
                    ->where($q->qualifyColumn('created_at'), '<=', Carbon::parse($until)->endOfDay())))
            ->indicateUsing(function (array $data): array {
                $indicators = [];

                if ($data['from'] ?? null) {
                    $indicators[] = Indicator::make('Created from '.Carbon::parse($data['from'])->toFormattedDateString())->removeField('from');
                }

                if ($data['until'] ?? null) {
                    $indicators[] = Indicator::make('Created until '.Carbon::parse($data['until'])->toFormattedDateString())->removeField('until');
                }

                return $indicators;
            });
    }
}
