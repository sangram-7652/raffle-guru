<?php

namespace App\Filament\Resources\Payments;

use App\Filament\Resources\Concerns\ReadOnlyResource;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Payments\Pages\ViewPayment;
use App\Filament\Resources\Payments\Schemas\PaymentInfolist;
use App\Filament\Resources\Payments\Tables\PaymentsTable;
use App\Models\Payment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/**
 * Read-only operational view of P6 payment attempts (P11.6).
 * PaymentService (markPaid/markFailed, driven by checkout verification)
 * and the signed webhook flow are the only writers of payment state;
 * markRefunded() only mirrors a refund the gateway itself reports
 * (docs/PAYMENTS_DESIGN_P6.md "Refund boundary") and P11.2 grants no
 * refund ability — so there is deliberately no mark-paid/failed, refund,
 * capture, retry or reconcile action here. List and view only, no form,
 * every mutating capability false (ReadOnlyResource). Viewing is gated
 * by PaymentPolicy (payment.view), which itself defines nothing else.
 *
 * Never displayed: gateway_signature (audit-only, no operational use)
 * and metadata (unused by P6 for payments). Webhook rows are not exposed
 * in P11.6.
 */
class PaymentResource extends Resource
{
    use ReadOnlyResource;

    protected static ?string $model = Payment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|UnitEnum|null $navigationGroup = 'Commerce';

    protected static ?int $navigationSort = 2;

    public static function infolist(Schema $schema): Schema
    {
        return PaymentInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PaymentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayments::route('/'),
            'view' => ViewPayment::route('/{record}'),
        ];
    }

    /**
     * Order and customer columns eager-loaded (one query each per page),
     * selecting only what the panel shows.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'order' => fn ($order) => $order->withTrashed()->select(['id', 'reference', 'status', 'currency', 'total', 'user_id']),
                'order.user:id,name,email',
            ]);
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
