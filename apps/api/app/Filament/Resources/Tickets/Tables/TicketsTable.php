<?php

namespace App\Filament\Resources\Tickets\Tables;

use App\Filament\Resources\Competitions\CompetitionResource;
use App\Filament\Resources\Entries\EntryResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Support\DateRangeFilter;
use App\Filament\Support\RelatedRecordFilters;
use App\Models\Ticket;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only: View is the only record action; no bulk/toolbar actions.
 * Paginated, filtered, searched and sorted in SQL.
 *
 * Ticket-number search is EXACT (numbers are plain integers; a partial
 * match on "12" would also hit 120, 1203, …). Combined with the
 * competition filter it resolves via the unique
 * (competition_id, ticket_number) index.
 */
class TicketsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('ticket_number')
                    ->label('Ticket no.')
                    ->weight('medium')
                    ->sortable()
                    ->searchable(query: fn (Builder $query, string $search): Builder => ctype_digit(ltrim($search, '#'))
                        ? $query->where('ticket_number', (int) ltrim($search, '#'))
                        : $query),
                TextColumn::make('competition.title')
                    ->label('Competition')
                    ->url(fn (Ticket $record): string => CompetitionResource::getUrl('view', ['record' => $record->competition_id]))
                    ->wrap()
                    ->searchable(query: fn (Builder $query, string $search): Builder => RelatedRecordFilters::searchCompetition($query, $search)),
                TextColumn::make('user.name')
                    ->label('Customer')
                    ->description(fn (Ticket $record): ?string => $record->user?->email)
                    ->searchable(query: fn (Builder $query, string $search): Builder => RelatedRecordFilters::searchCustomer($query, $search)),
                TextColumn::make('entry_id')
                    ->label('Entry')
                    ->formatStateUsing(fn (int $state): string => "#{$state}")
                    ->url(fn (Ticket $record): string => EntryResource::getUrl('view', ['record' => $record->entry_id])),
                TextColumn::make('order.reference')
                    ->label('Order')
                    ->url(fn (Ticket $record): string => OrderResource::getUrl('view', ['record' => $record->order_id]))
                    ->searchable(query: fn (Builder $query, string $search): Builder => RelatedRecordFilters::searchOrderReference($query, $search)),
                TextColumn::make('allocated_at')
                    ->label('Allocated')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                RelatedRecordFilters::competition(),
                RelatedRecordFilters::customer(),
                RelatedRecordFilters::order(),
                DateRangeFilter::make('allocated_between', 'allocated_at', 'Allocated'),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
