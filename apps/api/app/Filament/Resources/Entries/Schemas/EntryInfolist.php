<?php

namespace App\Filament\Resources\Entries\Schemas;

use App\Enums\OrderStatus;
use App\Filament\Resources\Competitions\CompetitionResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Support\OrderStatusBadge;
use App\Models\Entry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Answers "who entered which competition, through which order?".
 * Read-only. Money shown is the persisted order line (unit_price /
 * line_total) — never recalculated. The resulting tickets are listed
 * below by the read-only TicketsRelationManager (paginated, since one
 * entry can hold many tickets).
 */
class EntryInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Entry')
                    ->columnSpan(2)
                    ->columns(3)
                    ->components([
                        TextEntry::make('id')
                            ->label('Entry')
                            ->formatStateUsing(fn (int $state): string => "#{$state}")
                            ->weight('medium'),
                        TextEntry::make('quantity')
                            ->label('Tickets requested')
                            ->numeric(),
                        TextEntry::make('tickets_allocated')
                            ->label('Tickets allocated')
                            ->state(fn (Entry $record): int => $record->tickets()->count())
                            ->numeric(),
                        TextEntry::make('competition.title')
                            ->label('Competition')
                            ->url(fn (Entry $record): string => CompetitionResource::getUrl('view', ['record' => $record->competition_id]))
                            ->columnSpan(2),
                        TextEntry::make('created_at')->label('Entered')->dateTime(),
                    ]),

                Section::make('Customer')
                    ->columnSpan(1)
                    ->components([
                        TextEntry::make('user.name')->label('Name'),
                        TextEntry::make('user.email')->label('Email')->copyable(),
                    ]),

                Section::make('Order')
                    ->columnSpanFull()
                    ->columns(5)
                    ->components([
                        TextEntry::make('order.reference')
                            ->label('Reference')
                            ->url(fn (Entry $record): string => OrderResource::getUrl('view', ['record' => $record->order_id])),
                        TextEntry::make('order.status')
                            ->label('Order status')
                            ->badge()
                            ->formatStateUsing(fn (OrderStatus $state): string => OrderStatusBadge::label($state))
                            ->color(fn (OrderStatus $state): string => OrderStatusBadge::color($state)),
                        TextEntry::make('orderItem.quantity')
                            ->label('Line quantity')
                            ->numeric(),
                        TextEntry::make('orderItem.unit_price')
                            ->label('Unit price')
                            ->money(fn (Entry $record): string => $record->orderItem?->currency ?? 'GBP', divideBy: 100),
                        TextEntry::make('orderItem.line_total')
                            ->label('Line total')
                            ->money(fn (Entry $record): string => $record->orderItem?->currency ?? 'GBP', divideBy: 100),
                    ]),
            ]);
    }
}
