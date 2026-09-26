<?php

namespace App\Filament\Resources\Payments\Schemas;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Support\OrderStatusBadge;
use App\Filament\Support\PaymentStatusBadge;
use App\Models\Payment;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Answers "what happened with this payment?" — read-only. Amounts are
 * the persisted integer minor units, formatted for display only.
 * gateway_signature and metadata are deliberately not shown (see
 * PaymentResource).
 */
class PaymentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Payment')
                    ->columnSpan(2)
                    ->columns(3)
                    ->components([
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (PaymentStatus $state): string => PaymentStatusBadge::label($state))
                            ->color(fn (PaymentStatus $state): string => PaymentStatusBadge::color($state)),
                        TextEntry::make('amount')
                            ->money(fn (Payment $record): string => $record->currency, divideBy: 100)
                            ->weight('bold'),
                        TextEntry::make('currency'),
                        TextEntry::make('gateway')->badge()->color('gray'),
                        TextEntry::make('gateway_order_id')->label('Gateway order ID')->copyable(),
                        TextEntry::make('gateway_payment_id')->label('Gateway payment ID')->copyable()->placeholder('Not captured'),
                        TextEntry::make('created_at')->label('Attempted')->dateTime(),
                        TextEntry::make('paid_at')->dateTime()->placeholder('—'),
                        TextEntry::make('updated_at')->label('Last updated')->dateTime(),
                    ]),

                Section::make('Order')
                    ->columnSpan(1)
                    ->components([
                        TextEntry::make('order.reference')
                            ->label('Reference')
                            ->url(fn (Payment $record): string => OrderResource::getUrl('view', ['record' => $record->order_id])),
                        TextEntry::make('order.status')
                            ->label('Order status')
                            ->badge()
                            ->formatStateUsing(fn (OrderStatus $state): string => OrderStatusBadge::label($state))
                            ->color(fn (OrderStatus $state): string => OrderStatusBadge::color($state)),
                        TextEntry::make('order.total')
                            ->label('Order total')
                            ->money(fn (Payment $record): string => $record->order?->currency ?? $record->currency, divideBy: 100),
                        TextEntry::make('order.user.name')->label('Customer'),
                        TextEntry::make('order.user.email')->label('Customer email'),
                    ]),

                Section::make('Failure')
                    ->columnSpanFull()
                    ->columns(2)
                    ->visible(fn (Payment $record): bool => filled($record->failure_code) || filled($record->failure_message))
                    ->components([
                        TextEntry::make('failure_code')->placeholder('—'),
                        TextEntry::make('failure_message')->placeholder('—'),
                    ]),
            ]);
    }
}
