<?php

namespace App\Filament\Resources\Prizes\Tables;

use App\Filament\Resources\Prizes\PrizeResource;
use App\Models\Prize;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

/**
 * No bulk actions (delete/restore are per-record, gated on the parent
 * competition being pre-launch) and no force-delete — see
 * PrizeResource's docblock. `competition` is eager-loaded by
 * PrizeResource::getEloquentQuery(), so the Competition column costs
 * one query per page, not one per row.
 */
class PrizesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('competition.title')
                    ->label('Competition')
                    ->searchable()
                    ->placeholder('Deleted competition')
                    ->wrap(),
                TextColumn::make('value')
                    ->money('GBP', divideBy: 100)
                    ->sortable(),
                TextColumn::make('cash_alternative_value')
                    ->label('Cash alternative')
                    ->money('GBP', divideBy: 100)
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('quantity')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('deleted_at')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('competition')
                    ->relationship('competition', 'title')
                    ->searchable(),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                ActionGroup::make([
                    DeleteAction::make()
                        ->visible(fn (Prize $record): bool => PrizeResource::prizeSetIsEditable($record)),
                    RestoreAction::make()
                        ->visible(fn (Prize $record): bool => $record->trashed() && PrizeResource::prizeSetIsEditable($record)),
                ]),
            ]);
    }
}
