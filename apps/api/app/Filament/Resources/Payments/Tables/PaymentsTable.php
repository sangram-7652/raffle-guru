<?php

namespace App\Filament\Resources\Payments\Tables;

use App\Enums\PaymentStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Support\CreatedBetweenFilter;
use App\Filament\Support\PaymentStatusBadge;
use App\Models\Payment;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only: View is the only record action; no bulk/toolbar actions.
 * Searches are prefix matches on operational identifiers only (gateway
 * payment/order ids, order reference) — never on signatures or metadata.
 */
class PaymentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('gateway_payment_id')
                    ->label('Gateway payment')
                    ->description(fn (Payment $record): string => $record->gateway_order_id)
                    ->placeholder('Not captured')
                    ->copyable()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where(fn (Builder $q): Builder => $q
                            ->where('gateway_payment_id', 'like', $search.'%')
                            ->orWhere('gateway_order_id', 'like', $search.'%'))),
                TextColumn::make('order.reference')
                    ->label('Order')
                    ->url(fn (Payment $record): string => OrderResource::getUrl('view', ['record' => $record->order_id]))
                    ->description(fn (Payment $record): ?string => $record->order?->user?->email)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->whereHas('order', fn (Builder $order): Builder => $order
                            ->withTrashed()
                            ->where('reference', 'like', strtoupper($search).'%'))),
                TextColumn::make('gateway')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (PaymentStatus $state): string => PaymentStatusBadge::label($state))
                    ->color(fn (PaymentStatus $state): string => PaymentStatusBadge::color($state))
                    ->sortable(),
                TextColumn::make('amount')
                    ->money(fn (Payment $record): string => $record->currency, divideBy: 100)
                    ->sortable(),
                TextColumn::make('paid_at')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Attempted')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('deleted_at')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(PaymentStatusBadge::options())
                    ->multiple(),
                // Distinct values of small, low-cardinality columns.
                SelectFilter::make('gateway')
                    ->options(fn (): array => Payment::query()->distinct()->orderBy('gateway')->pluck('gateway', 'gateway')->all()),
                SelectFilter::make('currency')
                    ->options(fn (): array => Payment::query()->distinct()->orderBy('currency')->pluck('currency', 'currency')->all()),
                CreatedBetweenFilter::make(),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
