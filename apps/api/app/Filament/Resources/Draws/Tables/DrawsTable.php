<?php

namespace App\Filament\Resources\Draws\Tables;

use App\Enums\DrawStatus;
use App\Filament\Resources\Competitions\CompetitionResource;
use App\Filament\Resources\Tickets\TicketResource;
use App\Filament\Support\DateRangeFilter;
use App\Filament\Support\DrawStatusBadge;
use App\Filament\Support\RelatedRecordFilters;
use App\Models\Draw;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only list of draw attempts: View is the only record action and
 * there are no bulk actions. Paginated, filtered, searched and sorted in
 * SQL. The winning ticket shown is the persisted DrawResult's.
 */
class DrawsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('Draw')
                    ->formatStateUsing(fn (int $state): string => "#{$state}")
                    ->sortable()
                    ->searchable(query: fn (Builder $query, string $search): Builder => ctype_digit(ltrim($search, '#'))
                        ? $query->whereKey((int) ltrim($search, '#'))
                        : $query),
                TextColumn::make('competition.title')
                    ->label('Competition')
                    ->url(fn (Draw $record): string => CompetitionResource::getUrl('view', ['record' => $record->competition_id]))
                    ->wrap()
                    ->searchable(query: fn (Builder $query, string $search): Builder => RelatedRecordFilters::searchCompetition($query, $search)),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (DrawStatus $state): string => DrawStatusBadge::label($state))
                    ->color(fn (DrawStatus $state): string => DrawStatusBadge::color($state))
                    ->sortable(),
                TextColumn::make('attempt')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('result.ticket_number')
                    ->label('Winning ticket')
                    ->weight('bold')
                    ->placeholder('—')
                    ->url(fn (Draw $record): ?string => $record->result
                        ? TicketResource::getUrl('view', ['record' => $record->result->ticket_id])
                        : null)
                    // Plain digits only: "#N" means draw #N (as the ID
                    // column displays it), so it never also matches a
                    // winning ticket numbered N.
                    ->searchable(query: fn (Builder $query, string $search): Builder => ctype_digit($search)
                        ? $query->whereHas('result', fn (Builder $result): Builder => $result->where('ticket_number', (int) $search))
                        : $query),
                TextColumn::make('eligible_ticket_count')
                    ->label('Eligible tickets')
                    ->numeric()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('competition.draw_at')
                    ->label('Scheduled')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('started_at')
                    ->label('Started')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('completed_at')
                    ->label('Completed')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('failed_at')
                    ->label('Failed')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(DrawStatusBadge::options())
                    ->multiple(),
                RelatedRecordFilters::competition(),
                TernaryFilter::make('has_result')
                    ->label('Winning ticket drawn')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereHas('result'),
                        false: fn (Builder $query): Builder => $query->whereDoesntHave('result'),
                    ),
                DateRangeFilter::make('started_between', 'started_at', 'Started'),
                DateRangeFilter::make('completed_between', 'completed_at', 'Completed'),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
