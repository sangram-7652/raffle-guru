<?php

namespace App\Filament\Resources\Competitions\Schemas;

use App\Enums\CompetitionStatus;
use App\Filament\Support\CompetitionStatusBadge;
use App\Models\Competition;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Read-only. The only mutation on the view page is the "Transition
 * status" header action (see ViewCompetition). Prizes appear below via
 * PrizesRelationManager (read-only on this page, editable from Edit).
 *
 * skill_question.answer_index IS shown here: this is the authenticated
 * admin panel. The public API (App\Http\Resources\CompetitionResource,
 * unchanged by P11.5) is where it must never appear. Lifecycle audit
 * history is deliberately not shown — it names admins, which P11.2
 * gates behind audit.view (not granted to Operations).
 */
class CompetitionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Competition')
                    ->columnSpan(2)
                    ->columns(2)
                    ->components([
                        TextEntry::make('title'),
                        TextEntry::make('slug')->copyable(),
                        TextEntry::make('category')->badge()->color('gray'),
                        IconEntry::make('is_featured')
                            ->label('Featured')
                            ->boolean(),
                        TextEntry::make('short_description')
                            ->label('Short description')
                            ->columnSpanFull(),
                        TextEntry::make('description')
                            ->listWithLineBreaks()
                            ->columnSpanFull()
                            ->placeholder('—'),
                    ]),

                Section::make('Status')
                    ->columnSpan(1)
                    ->components([
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (CompetitionStatus $state): string => CompetitionStatusBadge::label($state))
                            ->color(fn (CompetitionStatus $state): string => CompetitionStatusBadge::color($state)),
                        TextEntry::make('starts_at')->label('Starts')->dateTime()->placeholder('Not set'),
                        TextEntry::make('ends_at')->label('Ends')->dateTime()->placeholder('Not set'),
                        TextEntry::make('draw_at')->label('Draw')->dateTime()->placeholder('Not set'),
                    ]),

                Section::make('Pricing & capacity')
                    ->columnSpanFull()
                    ->columns(4)
                    ->components([
                        TextEntry::make('entry_price')
                            ->label('Entry price')
                            ->money(fn (Competition $record): string => $record->currency, divideBy: 100),
                        TextEntry::make('entries_sold')
                            ->label('Entries sold')
                            ->state(fn (Competition $record): int => $record->tickets()->count())
                            ->numeric(),
                        TextEntry::make('max_entries')
                            ->label('Max entries')
                            ->numeric(),
                        TextEntry::make('max_entries_per_user')
                            ->label('Max per user')
                            ->numeric()
                            ->placeholder('No limit'),
                    ]),

                Section::make('Skill question')
                    ->columnSpanFull()
                    ->collapsible()
                    ->components([
                        TextEntry::make('skill_question.prompt')
                            ->label('Prompt')
                            ->placeholder('None — free entry'),
                        TextEntry::make('skill_question.options')
                            ->label('Options')
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->visible(fn (Competition $record): bool => filled($record->skill_question['options'] ?? null)),
                        TextEntry::make('skill_question.answer_index')
                            ->label('Correct answer (admin only)')
                            ->formatStateUsing(fn (Competition $record, $state): string => 'Option '.((int) $state + 1).': '
                                .($record->skill_question['options'][(int) $state] ?? '—'))
                            ->visible(fn (Competition $record): bool => isset($record->skill_question['answer_index'])),
                    ]),

                Section::make('Images')
                    ->columnSpanFull()
                    ->collapsible()
                    ->visible(fn (Competition $record): bool => filled($record->images))
                    ->components([
                        ImageEntry::make('images')
                            ->hiddenLabel()
                            ->imageHeight(120)
                            ->stacked(false),
                        TextEntry::make('images')
                            ->label('URLs')
                            ->listWithLineBreaks()
                            ->copyable(),
                    ]),

                Section::make('Record')
                    ->columnSpanFull()
                    ->columns(3)
                    ->collapsed()
                    ->components([
                        TextEntry::make('created_at')->dateTime(),
                        TextEntry::make('updated_at')->dateTime(),
                        TextEntry::make('deleted_at')
                            ->dateTime()
                            ->visible(fn (Competition $record): bool => $record->trashed()),
                    ]),
            ]);
    }
}
