<?php

namespace App\Filament\Resources\Promotions\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * See docs/EMAIL_CAPTURE.md "Option 1 proposal" — `is_enabled` defaults
 * to off; `total_capacity` has no "unlimited" option, by design (an
 * admin must pick a real number). `slug` is the public lookup key the
 * frontend footer CTA requests by — changing it on a live promotion
 * breaks that CTA until the frontend constant is updated to match.
 */
class PromotionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Promotion')
                ->columns(2)
                ->components([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),
                    TextInput::make('slug')
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true)
                        ->helperText('The frontend footer CTA requests this exact slug — coordinate any change.'),
                    Select::make('competition_id')
                        ->label('Eligible competition')
                        ->relationship('competition', 'title')
                        ->searchable(['title', 'slug'])
                        ->required()
                        ->helperText('The one competition a claimed free ticket enters.'),
                    Toggle::make('is_enabled')
                        ->label('Enabled')
                        ->default(false)
                        ->helperText('Nothing is claimable while this is off.'),
                ]),
            Section::make('Window & capacity')
                ->columns(3)
                ->components([
                    DateTimePicker::make('starts_at')
                        ->label('Starts at')
                        ->helperText('Leave blank for no start restriction.'),
                    DateTimePicker::make('ends_at')
                        ->label('Ends at')
                        ->helperText('Leave blank for no end restriction.'),
                    TextInput::make('total_capacity')
                        ->label('Total capacity')
                        ->required()
                        ->integer()
                        ->minValue(1)
                        ->maxValue(4_294_967_295)
                        ->helperText('Maximum number of fulfilled claims, ever.'),
                ]),
        ]);
    }
}
