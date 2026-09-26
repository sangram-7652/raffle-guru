<?php

namespace App\Filament\Resources\Competitions\Tables;

use App\Enums\CompetitionStatus;
use App\Filament\Support\CompetitionPreLaunch;
use App\Filament\Support\CompetitionStatusBadge;
use App\Models\Competition;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * `status` is a read-only badge — never inline-editable, never touched
 * by any action here. There are deliberately NO bulk actions: bulk
 * delete can't apply the per-record pre-launch rule below, and status
 * must never be bulk-changed (P11.5 §11). No force-delete anywhere —
 * see CompetitionResource's docblock.
 *
 * Server-side paginated by Filament; the only per-page extra query is
 * the tickets_count aggregate (tickets.competition_id is indexed by its
 * foreign key).
 */
class CompetitionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Competition $record): string => $record->slug)
                    ->wrap(),
                TextColumn::make('slug')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('category')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (CompetitionStatus $state): string => CompetitionStatusBadge::label($state))
                    ->color(fn (CompetitionStatus $state): string => CompetitionStatusBadge::color($state))
                    ->sortable(),
                TextColumn::make('entry_price')
                    ->label('Entry price')
                    ->money('GBP', divideBy: 100)
                    ->sortable(),
                TextColumn::make('tickets_count')
                    ->label('Entries sold')
                    ->counts('tickets')
                    ->numeric(),
                TextColumn::make('max_entries')
                    ->label('Max entries')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('starts_at')
                    ->label('Starts')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('ends_at')
                    ->label('Ends')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('draw_at')
                    ->label('Draw')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(),
                IconColumn::make('is_featured')
                    ->label('Featured')
                    ->boolean()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('deleted_at')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            // Newest first, on the primary key (monotonic with
            // created_at, and indexed).
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(CompetitionStatusBadge::options())
                    ->multiple(),
                SelectFilter::make('category')
                    ->options(fn (): array => Competition::query()
                        ->distinct()
                        ->orderBy('category')
                        ->pluck('category', 'category')
                        ->all())
                    ->searchable(),
                TernaryFilter::make('is_featured')
                    ->label('Featured'),
                Filter::make('ending_soon')
                    ->label('Active, ending within 24h')
                    ->query(fn (Builder $query): Builder => $query
                        ->where('status', CompetitionStatus::Active)
                        ->whereBetween('ends_at', [now(), now()->addDay()])),
                Filter::make('draw_due')
                    ->label('Closed, draw time reached')
                    ->query(fn (Builder $query): Builder => $query
                        ->where('status', CompetitionStatus::Closed)
                        ->where('draw_at', '<=', now())),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                ActionGroup::make([
                    DeleteAction::make()
                        ->visible(fn (Competition $record): bool => CompetitionPreLaunch::is($record)),
                    RestoreAction::make(),
                ]),
            ]);
    }
}
