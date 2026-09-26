<?php

namespace App\Filament\Resources\Orders;

use App\Filament\Resources\Concerns\ReadOnlyResource;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\Schemas\OrderInfolist;
use App\Filament\Resources\Orders\Tables\OrdersTable;
use App\Models\Order;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/**
 * Read-only operational view of P5 orders (P11.6). Orders are
 * historical commerce records: status is written only by the P5/P6
 * checkout + PaymentService flow, and prices/totals are
 * server-authoritative snapshots. There is no order lifecycle operation
 * in the domain for an admin to invoke (nothing cancels, processes or
 * completes an order), so this resource exposes none — list and view
 * pages only, no form, and every mutating capability is false
 * (ReadOnlyResource). Viewing is gated by OrderPolicy (order.view).
 */
class OrderResource extends Resource
{
    use ReadOnlyResource;

    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static string|UnitEnum|null $navigationGroup = 'Commerce';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'reference';

    public static function infolist(Schema $schema): Schema
    {
        return OrderInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OrdersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'view' => ViewOrder::route('/{record}'),
        ];
    }

    /**
     * Customer columns and the latest payment are eager-loaded (one query
     * each per page); only the user columns the panel shows are selected,
     * so password/remember_token never leave the database.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['user:id,name,email', 'latestPayment'])
            ->withCount('items');
    }

    /**
     * @return list<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['reference'];
    }

    /**
     * Soft-deleted orders are still history worth viewing.
     */
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
