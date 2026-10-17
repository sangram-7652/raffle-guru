<?php

namespace App\Filament\Resources\Promotions\Tables;

use App\Models\Promotion;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * `competition` is eager-loaded by PromotionResource::getEloquentQuery()
 * — the Competition column costs one query per page, not one per row.
 * "Claimed" is a live count (PromotionClaim::Fulfilled), not a
 * denormalized counter — the row-lock in PromotionClaimService is the
 * actual capacity guarantee; this table is a display read only.
 */
class PromotionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('competition.title')
                    ->label('Competition')
                    ->placeholder('Deleted competition')
                    ->wrap(),
                IconColumn::make('is_enabled')
                    ->label('Enabled')
                    ->boolean(),
                TextColumn::make('claimed')
                    ->label('Claimed / capacity')
                    ->state(fn (Promotion $record): string => "{$record->fulfilledClaimsCount()} / {$record->total_capacity}"),
                TextColumn::make('starts_at')
                    ->dateTime()
                    ->placeholder('No start restriction')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('ends_at')
                    ->dateTime()
                    ->placeholder('No end restriction')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
