<?php

namespace App\Filament\Support;

use Filament\Forms\Components\DatePicker;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * "From / until" date-range filter on one of the table's own timestamp
 * columns, applied in SQL. Dates are whole days in the app timezone.
 */
final class DateRangeFilter
{
    public static function make(string $name, string $column, string $label): Filter
    {
        return Filter::make($name)
            ->label($label)
            ->schema([
                DatePicker::make('from')->label("{$label} from"),
                DatePicker::make('until')->label("{$label} until"),
            ])
            ->query(fn (Builder $query, array $data): Builder => $query
                ->when($data['from'] ?? null, fn (Builder $q, string $from): Builder => $q
                    ->where($q->qualifyColumn($column), '>=', Carbon::parse($from)->startOfDay()))
                ->when($data['until'] ?? null, fn (Builder $q, string $until): Builder => $q
                    ->where($q->qualifyColumn($column), '<=', Carbon::parse($until)->endOfDay())))
            ->indicateUsing(function (array $data) use ($label): array {
                $indicators = [];

                if ($data['from'] ?? null) {
                    $indicators[] = Indicator::make("{$label} from ".Carbon::parse($data['from'])->toFormattedDateString())->removeField('from');
                }

                if ($data['until'] ?? null) {
                    $indicators[] = Indicator::make("{$label} until ".Carbon::parse($data['until'])->toFormattedDateString())->removeField('until');
                }

                return $indicators;
            });
    }
}
