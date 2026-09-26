<?php

namespace App\Filament\Resources\Prizes\Schemas;

use App\Filament\Resources\Competitions\CompetitionResource;
use App\Models\Prize;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PrizeInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Prize')
                    ->columns(2)
                    ->columnSpanFull()
                    ->components([
                        TextEntry::make('title'),
                        // withTrashed: Competition::prizes() is a plain
                        // relation, so a soft-deleted parent would
                        // otherwise render as blank.
                        TextEntry::make('competition_title')
                            ->label('Competition')
                            ->state(fn (Prize $record): ?string => $record->competition()->withTrashed()->value('title'))
                            ->url(fn (Prize $record): string => CompetitionResource::getUrl('view', ['record' => $record->competition_id])),
                        TextEntry::make('description')
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('value')
                            ->money(fn (Prize $record): string => $record->currency, divideBy: 100),
                        TextEntry::make('cash_alternative_value')
                            ->label('Cash alternative')
                            ->money(fn (Prize $record): string => $record->currency, divideBy: 100)
                            ->placeholder('—'),
                        TextEntry::make('quantity')
                            ->numeric(),
                        TextEntry::make('currency'),
                    ]),
                Section::make('Record')
                    ->columns(3)
                    ->columnSpanFull()
                    ->collapsed()
                    ->components([
                        TextEntry::make('created_at')->dateTime(),
                        TextEntry::make('updated_at')->dateTime(),
                        TextEntry::make('deleted_at')
                            ->dateTime()
                            ->visible(fn (Prize $record): bool => $record->trashed()),
                    ]),
            ]);
    }
}
