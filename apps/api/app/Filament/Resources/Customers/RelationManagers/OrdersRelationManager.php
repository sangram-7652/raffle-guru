<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Support\OrderStatusBadge;
use App\Filament\Support\PaymentStatusBadge;
use App\Models\Order;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * The customer's orders, read-only. Gated by OrderPolicy (order.view)
 * as well as the customer view, so an admin without order visibility
 * never sees this section at all. Competition context is the order
 * line's own competition_title snapshot, not a live join. Order state,
 * totals and prices are never editable from here (OrderResource is
 * itself read-only).
 */
class OrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'orders';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return 'Orders';
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Gate::allows('viewAny', Order::class);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('reference')
            ->columns([
                TextColumn::make('reference')
                    ->weight('medium')
                    ->copyable()
                    ->url(fn (Order $record): string => OrderResource::getUrl('view', ['record' => $record])),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (OrderStatus $state): string => OrderStatusBadge::label($state))
                    ->color(fn (OrderStatus $state): string => OrderStatusBadge::color($state)),
                TextColumn::make('items.competition_title')
                    ->label('Competition')
                    ->listWithLineBreaks()
                    ->wrap(),
                TextColumn::make('items_count')
                    ->label('Lines')
                    ->numeric(),
                TextColumn::make('total')
                    ->money(fn (Order $record): string => $record->currency, divideBy: 100),
                TextColumn::make('latestPayment.status')
                    ->label('Latest payment')
                    ->badge()
                    ->formatStateUsing(fn (?PaymentStatus $state): ?string => PaymentStatusBadge::label($state))
                    ->color(fn (?PaymentStatus $state): string => PaymentStatusBadge::color($state))
                    ->placeholder('None'),
                TextColumn::make('created_at')
                    ->label('Placed')
                    ->dateTime()
                    ->sortable(),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                // Soft-deleted orders are still customer history.
                ->withTrashed()
                ->withCount('items')
                ->with([
                    'items' => fn ($q) => $q->select(['id', 'order_id', 'competition_title'])->orderBy('id'),
                    'latestPayment' => fn ($q) => $q->select(['payments.id', 'payments.order_id', 'payments.status']),
                ]))
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (Order $record): string => OrderResource::getUrl('view', ['record' => $record]));
    }
}
