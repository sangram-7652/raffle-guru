<?php

namespace App\Filament\Resources\Promotions\Schemas;

use App\Filament\Resources\Competitions\CompetitionResource;
use App\Models\Promotion;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PromotionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Promotion')
                    ->columns(2)
                    ->columnSpanFull()
                    ->components([
                        TextEntry::make('name'),
                        TextEntry::make('slug')->copyable(),
                        TextEntry::make('competition.title')
                            ->label('Competition')
                            ->url(fn (Promotion $record): string => CompetitionResource::getUrl('view', ['record' => $record->competition_id])),
                        IconEntry::make('is_enabled')->label('Enabled')->boolean(),
                        TextEntry::make('starts_at')->dateTime()->placeholder('No start restriction'),
                        TextEntry::make('ends_at')->dateTime()->placeholder('No end restriction'),
                        TextEntry::make('claimed')
                            ->label('Claimed / capacity')
                            ->state(fn (Promotion $record): string => "{$record->fulfilledClaimsCount()} / {$record->total_capacity}"),
                    ]),
                Section::make('Record')
                    ->columns(2)
                    ->columnSpanFull()
                    ->collapsed()
                    ->components([
                        TextEntry::make('created_at')->dateTime(),
                        TextEntry::make('updated_at')->dateTime(),
                    ]),
            ]);
    }
}
