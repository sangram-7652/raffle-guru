<?php

namespace App\Filament\Resources\Winners\Tables;

use App\Enums\WinnerClaimStatus;
use App\Enums\WinnerStatus;
use App\Filament\Resources\Competitions\CompetitionResource;
use App\Filament\Resources\Tickets\TicketResource;
use App\Filament\Support\CreatedBetweenFilter;
use App\Filament\Support\RelatedRecordFilters;
use App\Filament\Support\WinnerClaimStatusBadge;
use App\Filament\Support\WinnerStatusBadge;
use App\Models\Prize;
use App\Models\Winner;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only list: View is the only record action; no bulk actions.
 * Paginated, filtered, searched and sorted in SQL.
 */
class WinnersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('Winner')
                    ->formatStateUsing(fn (int $state): string => "#{$state}")
                    ->sortable()
                    ->searchable(query: fn (Builder $query, string $search): Builder => str_starts_with($search, '#') && ctype_digit(substr($search, 1))
                        ? $query->whereKey((int) substr($search, 1))
                        : $query),
                TextColumn::make('competition.title')
                    ->label('Competition')
                    ->url(fn (Winner $record): string => CompetitionResource::getUrl('view', ['record' => $record->competition_id]))
                    ->wrap()
                    ->searchable(query: fn (Builder $query, string $search): Builder => RelatedRecordFilters::searchCompetition($query, $search)),
                TextColumn::make('ticket.ticket_number')
                    ->label('Ticket no.')
                    ->weight('medium')
                    ->url(fn (Winner $record): string => TicketResource::getUrl('view', ['record' => $record->ticket_id]))
                    ->searchable(query: fn (Builder $query, string $search): Builder => ctype_digit($search)
                        ? $query->whereHas('ticket', fn (Builder $ticket): Builder => $ticket->where('ticket_number', (int) $search))
                        : $query),
                TextColumn::make('user.name')
                    ->label('Customer')
                    ->description(fn (Winner $record): ?string => $record->user?->email)
                    ->searchable(query: fn (Builder $query, string $search): Builder => RelatedRecordFilters::searchCustomer($query, $search)),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (WinnerStatus $state): string => WinnerStatusBadge::label($state))
                    ->color(fn (WinnerStatus $state): string => WinnerStatusBadge::color($state))
                    ->sortable(),
                TextColumn::make('claim.status')
                    ->label('Claim')
                    ->badge()
                    ->formatStateUsing(fn (?WinnerClaimStatus $state): ?string => WinnerClaimStatusBadge::label($state))
                    ->color(fn (?WinnerClaimStatus $state): string => WinnerClaimStatusBadge::color($state))
                    ->placeholder('No claim'),
                TextColumn::make('delivery.prize.title')
                    ->label('Prize')
                    ->placeholder('Not resolved yet')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Won')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(WinnerStatusBadge::options())
                    ->multiple(),
                RelatedRecordFilters::competition(),
                RelatedRecordFilters::customer(),
                // The prize lives on the P10 delivery row.
                Filter::make('prize')
                    ->schema([
                        Select::make('prize_id')
                            ->label('Prize')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => Prize::query()
                                ->withTrashed()
                                ->where('title', 'like', "%{$search}%")
                                ->orderByDesc('id')
                                ->limit(50)
                                ->pluck('title', 'id')
                                ->all())
                            ->getOptionLabelUsing(fn ($value): ?string => Prize::query()->withTrashed()->whereKey($value)->value('title')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['prize_id'] ?? null,
                        fn (Builder $q, $id): Builder => $q->whereHas('delivery', fn (Builder $d): Builder => $d->where('prize_id', $id)),
                    )),
                Filter::make('draw')
                    ->schema([
                        TextInput::make('draw_id')->label('Draw #')->integer()->minValue(1),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['draw_id'] ?? null,
                        fn (Builder $q, $id): Builder => $q->where('draw_id', (int) $id),
                    )),
                TernaryFilter::make('has_claim')
                    ->label('Claim submitted')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereHas('claim'),
                        false: fn (Builder $query): Builder => $query->whereDoesntHave('claim'),
                    ),
                CreatedBetweenFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
