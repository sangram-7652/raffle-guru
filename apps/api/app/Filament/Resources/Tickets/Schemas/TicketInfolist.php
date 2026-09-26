<?php

namespace App\Filament\Resources\Tickets\Schemas;

use App\Enums\CompetitionStatus;
use App\Enums\OrderStatus;
use App\Filament\Resources\Competitions\CompetitionResource;
use App\Filament\Resources\Entries\EntryResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Support\CompetitionStatusBadge;
use App\Filament\Support\OrderStatusBadge;
use App\Models\DrawResult;
use App\Models\Ticket;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Answers "which ticket belongs to which entry/customer/order?".
 * Read-only; every field is immutable P7 allocation data.
 *
 * "Winning ticket" is a read of P9's existing record, not a new flag:
 * draw_results.ticket_id (FK, at most one result per competition) names
 * the drawn ticket. No draw controls or links — draw management is P11.8.
 */
class TicketInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Ticket')
                    ->columnSpan(2)
                    ->columns(3)
                    ->components([
                        TextEntry::make('ticket_number')
                            ->label('Ticket no.')
                            ->size('lg')
                            ->weight('bold')
                            ->copyable(),
                        TextEntry::make('allocated_at')->label('Allocated')->dateTime(),
                        IconEntry::make('is_winning_ticket')
                            ->label('Winning ticket')
                            ->state(fn (Ticket $record): bool => DrawResult::query()->where('ticket_id', $record->id)->exists())
                            ->boolean(),
                        TextEntry::make('competition.title')
                            ->label('Competition')
                            ->url(fn (Ticket $record): string => CompetitionResource::getUrl('view', ['record' => $record->competition_id]))
                            ->columnSpan(2),
                        TextEntry::make('competition.status')
                            ->label('Competition status')
                            ->badge()
                            ->formatStateUsing(fn (CompetitionStatus $state): string => CompetitionStatusBadge::label($state))
                            ->color(fn (CompetitionStatus $state): string => CompetitionStatusBadge::color($state)),
                    ]),

                Section::make('Customer')
                    ->columnSpan(1)
                    ->components([
                        TextEntry::make('user.name')->label('Name'),
                        TextEntry::make('user.email')->label('Email')->copyable(),
                    ]),

                Section::make('Allocated from')
                    ->columnSpanFull()
                    ->columns(3)
                    ->components([
                        TextEntry::make('entry_id')
                            ->label('Entry')
                            ->formatStateUsing(fn (int $state): string => "#{$state}")
                            ->url(fn (Ticket $record): string => EntryResource::getUrl('view', ['record' => $record->entry_id])),
                        TextEntry::make('order.reference')
                            ->label('Order')
                            ->url(fn (Ticket $record): string => OrderResource::getUrl('view', ['record' => $record->order_id])),
                        TextEntry::make('order.status')
                            ->label('Order status')
                            ->badge()
                            ->formatStateUsing(fn (OrderStatus $state): string => OrderStatusBadge::label($state))
                            ->color(fn (OrderStatus $state): string => OrderStatusBadge::color($state)),
                    ]),
            ]);
    }
}
