<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Filament\Resources\Competitions\CompetitionResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Support\OrderStatusBadge;
use App\Filament\Support\PaymentStatusBadge;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Ticket;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Answers "what happened with this order?" — read-only throughout.
 * Every amount shown is the PERSISTED value (orders.subtotal/total,
 * order_items.unit_price/line_total, payments.amount): nothing is
 * recalculated here, so historical records stay historical. Line items
 * show the competition_title snapshot taken at purchase time.
 *
 * Customer data is limited to name and email (the only customer fields
 * that exist besides credentials); password/remember_token are never
 * selected (see OrderResource::getEloquentQuery()).
 */
class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Order')
                    ->columnSpan(2)
                    ->columns(3)
                    ->components([
                        TextEntry::make('reference')->copyable()->weight('medium'),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (OrderStatus $state): string => OrderStatusBadge::label($state))
                            ->color(fn (OrderStatus $state): string => OrderStatusBadge::color($state)),
                        TextEntry::make('currency'),
                        TextEntry::make('subtotal')
                            ->money(fn (Order $record): string => $record->currency, divideBy: 100),
                        TextEntry::make('total')
                            ->money(fn (Order $record): string => $record->currency, divideBy: 100)
                            ->weight('bold'),
                        TextEntry::make('tickets_allocated')
                            ->label('Tickets allocated')
                            ->state(fn (Order $record): int => Ticket::query()->where('order_id', $record->id)->count())
                            ->numeric(),
                        TextEntry::make('created_at')->label('Placed')->dateTime(),
                        TextEntry::make('updated_at')->label('Last updated')->dateTime(),
                        TextEntry::make('deleted_at')
                            ->dateTime()
                            ->visible(fn (Order $record): bool => $record->trashed()),
                    ]),

                Section::make('Customer')
                    ->columnSpan(1)
                    ->components([
                        TextEntry::make('user.name')->label('Name'),
                        TextEntry::make('user.email')->label('Email')->copyable(),
                    ]),

                Section::make('Items')
                    ->columnSpanFull()
                    ->components([
                        RepeatableEntry::make('items')
                            ->hiddenLabel()
                            ->columns(4)
                            ->schema([
                                TextEntry::make('competition_title')
                                    ->label('Competition')
                                    ->url(fn (OrderItem $record): string => CompetitionResource::getUrl('view', ['record' => $record->competition_id])),
                                TextEntry::make('quantity')->numeric(),
                                TextEntry::make('unit_price')
                                    ->label('Unit price')
                                    ->money(fn (OrderItem $record): string => $record->currency, divideBy: 100),
                                TextEntry::make('line_total')
                                    ->label('Line total')
                                    ->money(fn (OrderItem $record): string => $record->currency, divideBy: 100),
                            ]),
                    ]),

                Section::make('Payments')
                    ->description('Every payment attempt for this order, newest first. Payment state is owned by the payment flow and webhooks.')
                    ->columnSpanFull()
                    ->components([
                        RepeatableEntry::make('payments')
                            ->hiddenLabel()
                            ->state(fn (Order $record) => $record->payments()->latest('id')->get())
                            ->columns(5)
                            ->placeholder('No payment attempts yet.')
                            ->schema([
                                TextEntry::make('status')
                                    ->badge()
                                    ->formatStateUsing(fn (PaymentStatus $state): string => PaymentStatusBadge::label($state))
                                    ->color(fn (PaymentStatus $state): string => PaymentStatusBadge::color($state))
                                    ->url(fn (Payment $record): string => PaymentResource::getUrl('view', ['record' => $record])),
                                TextEntry::make('amount')
                                    ->money(fn (Payment $record): string => $record->currency, divideBy: 100),
                                TextEntry::make('gateway')->badge()->color('gray'),
                                TextEntry::make('paid_at')->dateTime()->placeholder('—'),
                                TextEntry::make('failure_message')->label('Failure')->placeholder('—'),
                            ]),
                    ]),
            ]);
    }
}
