<?php

namespace App\Filament\Resources\WinnerClaims\Tables;

use App\Enums\WinnerClaimStatus;
use App\Filament\Resources\Winners\WinnerResource;
use App\Filament\Support\DateRangeFilter;
use App\Filament\Support\RelatedRecordFilters;
use App\Filament\Support\WinnerClaimStatusBadge;
use App\Models\WinnerClaim;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only list: View is the only record action (review actions live
 * on the view page); no bulk actions. Filters/search reach the
 * competition and customer THROUGH the winner, in SQL.
 */
class WinnerClaimsTable
{
    public static function configure(Table $table): Table
    {
        $throughWinner = fn (callable $scope): \Closure => fn (Builder $query, string $search): Builder => $query
            ->whereHas('winner', fn (Builder $winner): Builder => $scope($winner, $search));

        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('Claim')
                    ->formatStateUsing(fn (int $state): string => "#{$state}")
                    ->sortable()
                    ->searchable(query: fn (Builder $query, string $search): Builder => str_starts_with($search, '#') && ctype_digit(substr($search, 1))
                        ? $query->whereKey((int) substr($search, 1))
                        : $query),
                TextColumn::make('winner_id')
                    ->label('Winner')
                    ->formatStateUsing(fn (int $state): string => "#{$state}")
                    ->url(fn (WinnerClaim $record): string => WinnerResource::getUrl('view', ['record' => $record->winner_id])),
                TextColumn::make('winner.competition.title')
                    ->label('Competition')
                    ->wrap()
                    ->searchable(query: $throughWinner(fn (Builder $w, string $s): Builder => RelatedRecordFilters::searchCompetition($w, $s))),
                TextColumn::make('winner.ticket.ticket_number')
                    ->label('Ticket no.')
                    ->searchable(query: fn (Builder $query, string $search): Builder => ctype_digit($search)
                        ? $query->whereHas('winner.ticket', fn (Builder $t): Builder => $t->where('ticket_number', (int) $search))
                        : $query),
                TextColumn::make('winner.user.name')
                    ->label('Customer')
                    ->description(fn (WinnerClaim $record): ?string => $record->winner?->user?->email)
                    ->searchable(query: $throughWinner(fn (Builder $w, string $s): Builder => RelatedRecordFilters::searchCustomer($w, $s))),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (WinnerClaimStatus $state): string => WinnerClaimStatusBadge::label($state))
                    ->color(fn (WinnerClaimStatus $state): string => WinnerClaimStatusBadge::color($state))
                    ->sortable(),
                TextColumn::make('submitted_at')
                    ->label('Submitted')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(WinnerClaimStatusBadge::options())
                    ->multiple(),
                Filter::make('competition')
                    ->schema(RelatedRecordFilters::competition()->getSchemaComponents())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['competition_id'] ?? null,
                        fn (Builder $q, $id): Builder => $q->whereHas('winner', fn (Builder $w): Builder => $w->where('competition_id', $id)),
                    )),
                DateRangeFilter::make('submitted_between', 'submitted_at', 'Submitted'),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
