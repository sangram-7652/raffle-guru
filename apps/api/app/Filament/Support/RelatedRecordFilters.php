<?php

namespace App\Filament\Support;

use App\Models\Competition;
use App\Models\Order;
use Filament\Forms\Components\Select;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filters and searches shared by tables whose rows carry their own
 * competition_id / order_id / user_id columns (entries, tickets). All
 * applied in SQL: selects are searchable and never preloaded (50
 * results at a time), and searches are PREFIX matches where an index
 * exists (orders.reference, users.email) — see OrdersTable.
 */
final class RelatedRecordFilters
{
    public static function competition(): Filter
    {
        return Filter::make('competition')
            ->schema([
                Select::make('competition_id')
                    ->label('Competition')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => Competition::query()
                        ->withTrashed()
                        ->where(fn (Builder $q): Builder => $q
                            ->where('title', 'like', "%{$search}%")
                            ->orWhere('slug', 'like', "{$search}%"))
                        ->orderByDesc('id')
                        ->limit(50)
                        ->pluck('title', 'id')
                        ->all())
                    ->getOptionLabelUsing(fn ($value): ?string => Competition::query()->withTrashed()->whereKey($value)->value('title')),
            ])
            ->query(fn (Builder $query, array $data): Builder => $query->when(
                $data['competition_id'] ?? null,
                fn (Builder $q, $id): Builder => $q->where($q->qualifyColumn('competition_id'), $id),
            ))
            ->indicateUsing(fn (array $data): ?string => filled($data['competition_id'] ?? null)
                ? 'Competition: '.Competition::query()->withTrashed()->whereKey($data['competition_id'])->value('title')
                : null);
    }

    public static function order(): Filter
    {
        return Filter::make('order')
            ->schema([
                Select::make('order_id')
                    ->label('Order')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => Order::query()
                        ->withTrashed()
                        ->where('reference', 'like', strtoupper($search).'%')
                        ->orderByDesc('id')
                        ->limit(50)
                        ->pluck('reference', 'id')
                        ->all())
                    ->getOptionLabelUsing(fn ($value): ?string => Order::query()->withTrashed()->whereKey($value)->value('reference')),
            ])
            ->query(fn (Builder $query, array $data): Builder => $query->when(
                $data['order_id'] ?? null,
                fn (Builder $q, $id): Builder => $q->where($q->qualifyColumn('order_id'), $id),
            ))
            ->indicateUsing(fn (array $data): ?string => filled($data['order_id'] ?? null)
                ? 'Order: '.Order::query()->withTrashed()->whereKey($data['order_id'])->value('reference')
                : null);
    }

    public static function customer(): SelectFilter
    {
        return SelectFilter::make('user')
            ->label('Customer')
            ->relationship('user', 'email')
            ->searchable();
    }

    public static function searchCustomer(Builder $query, string $search): Builder
    {
        return $query->whereHas('user', fn (Builder $user): Builder => $user
            ->where('email', 'like', $search.'%')
            ->orWhere('name', 'like', $search.'%'));
    }

    public static function searchOrderReference(Builder $query, string $search): Builder
    {
        return $query->whereHas('order', fn (Builder $order): Builder => $order
            ->withTrashed()
            ->where('reference', 'like', strtoupper($search).'%'));
    }

    public static function searchCompetition(Builder $query, string $search): Builder
    {
        return $query->whereHas('competition', fn (Builder $competition): Builder => $competition
            ->withTrashed()
            ->where(fn (Builder $q): Builder => $q
                ->where('title', 'like', "%{$search}%")
                ->orWhere('slug', 'like', "{$search}%")));
    }
}
