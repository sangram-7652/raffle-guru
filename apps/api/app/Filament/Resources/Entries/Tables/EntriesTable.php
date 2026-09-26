<?php

namespace App\Filament\Resources\Entries\Tables;

use App\Filament\Resources\Competitions\CompetitionResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Support\CreatedBetweenFilter;
use App\Filament\Support\RelatedRecordFilters;
use App\Models\Entry;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only: View is the only record action; no bulk/toolbar actions.
 * Paginated, filtered, searched and sorted in SQL.
 */
class EntriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('Entry')
                    ->formatStateUsing(fn (int $state): string => "#{$state}")
                    ->sortable()
                    ->searchable(query: fn (Builder $query, string $search): Builder => ctype_digit(ltrim($search, '#'))
                        ? $query->whereKey((int) ltrim($search, '#'))
                        : $query),
                TextColumn::make('competition.title')
                    ->label('Competition')
                    ->url(fn (Entry $record): string => CompetitionResource::getUrl('view', ['record' => $record->competition_id]))
                    ->wrap()
                    ->searchable(query: fn (Builder $query, string $search): Builder => RelatedRecordFilters::searchCompetition($query, $search)),
                TextColumn::make('user.name')
                    ->label('Customer')
                    ->description(fn (Entry $record): ?string => $record->user?->email)
                    ->searchable(query: fn (Builder $query, string $search): Builder => RelatedRecordFilters::searchCustomer($query, $search)),
                TextColumn::make('order.reference')
                    ->label('Order')
                    ->url(fn (Entry $record): string => OrderResource::getUrl('view', ['record' => $record->order_id]))
                    ->searchable(query: fn (Builder $query, string $search): Builder => RelatedRecordFilters::searchOrderReference($query, $search)),
                TextColumn::make('quantity')
                    ->label('Tickets')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Entered')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                RelatedRecordFilters::competition(),
                RelatedRecordFilters::customer(),
                RelatedRecordFilters::order(),
                CreatedBetweenFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
