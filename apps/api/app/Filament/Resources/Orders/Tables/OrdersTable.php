<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Filament\Support\CreatedBetweenFilter;
use App\Filament\Support\OrderStatusBadge;
use App\Filament\Support\PaymentStatusBadge;
use App\Models\Competition;
use App\Models\Order;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only: the only record action is View, and there are no bulk or
 * toolbar actions. Everything is paginated, filtered, searched and
 * sorted in SQL. Searches are PREFIX matches ("RG-12…", "jane@…") so
 * they can use the unique orders.reference and users.email indexes
 * rather than unbounded %term% scans.
 */
class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->weight('medium')
                    ->copyable()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where('reference', 'like', strtoupper($search).'%')),
                TextColumn::make('user.name')
                    ->label('Customer')
                    ->description(fn (Order $record): ?string => $record->user?->email)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->whereHas('user', fn (Builder $user): Builder => $user
                            ->where('email', 'like', $search.'%')
                            ->orWhere('name', 'like', $search.'%'))),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (OrderStatus $state): string => OrderStatusBadge::label($state))
                    ->color(fn (OrderStatus $state): string => OrderStatusBadge::color($state))
                    ->sortable(),
                TextColumn::make('items_count')
                    ->label('Lines')
                    ->numeric(),
                TextColumn::make('total')
                    ->money(fn (Order $record): string => $record->currency, divideBy: 100)
                    ->sortable(),
                TextColumn::make('latestPayment.status')
                    ->label('Latest payment')
                    ->badge()
                    ->formatStateUsing(fn (?PaymentStatus $state): ?string => PaymentStatusBadge::label($state))
                    ->color(fn (?PaymentStatus $state): string => PaymentStatusBadge::color($state))
                    ->placeholder('None'),
                TextColumn::make('created_at')
                    ->label('Placed')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('deleted_at')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            // Newest first on the primary key (monotonic with created_at).
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(OrderStatusBadge::options())
                    ->multiple(),
                CreatedBetweenFilter::make(),
                // Searchable, never preloaded — options come back 50 at a time.
                SelectFilter::make('user')
                    ->label('Customer')
                    ->relationship('user', 'email')
                    ->searchable(),
                Filter::make('competition')
                    ->schema([
                        Select::make('competition_id')
                            ->label('Competition')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => Competition::query()
                                ->withTrashed()
                                ->where('title', 'like', "%{$search}%")
                                ->orderByDesc('id')
                                ->limit(50)
                                ->pluck('title', 'id')
                                ->all())
                            ->getOptionLabelUsing(fn ($value): ?string => Competition::query()->withTrashed()->whereKey($value)->value('title')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['competition_id'] ?? null,
                        fn (Builder $q, $competitionId): Builder => $q->whereHas(
                            'items',
                            fn (Builder $items): Builder => $items->where('competition_id', $competitionId),
                        ),
                    )),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
