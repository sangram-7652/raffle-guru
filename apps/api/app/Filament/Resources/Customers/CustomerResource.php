<?php

namespace App\Filament\Resources\Customers;

use App\Filament\Resources\Concerns\ReadOnlyResource;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\RelationManagers\EntriesRelationManager;
use App\Filament\Resources\Customers\RelationManagers\OrdersRelationManager;
use App\Filament\Resources\Customers\RelationManagers\TicketsRelationManager;
use App\Filament\Resources\Customers\RelationManagers\WinnersRelationManager;
use App\Filament\Resources\Customers\Schemas\CustomerInfolist;
use App\Filament\Resources\Customers\Tables\CustomersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/**
 * P11.11 — read-only customer administration.
 *
 * Customers are the P3 `users` table, served here as the admin's
 * window onto who the customer is and what they have done in the
 * competition domain. Nothing on this resource mutates a customer:
 *
 * - There is no customer lifecycle operation in the domain. Accounts
 *   are soft-deletable (SoftDeletes) but no service archives or
 *   restores them, no policy method permits it, and no audit trail
 *   records it, so archive/restore is deliberately NOT offered here.
 *   The "Archived" filter only reveals rows that already are.
 * - Profile/email correction has no domain operation either, so every
 *   customer field stays read-only (ReadOnlyResource).
 *
 * Orders, entries, tickets and winners/claims are shown by read-only
 * relation managers that link out to their own resources (OrderResource,
 * EntryResource, TicketResource, WinnerResource) for anything beyond
 * viewing — WinnerResource remains the only place fulfilment and claim
 * actions exist. Viewing is gated by UserPolicy (customer.view).
 */
class CustomerResource extends Resource
{
    use ReadOnlyResource;

    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Commerce';

    protected static ?int $navigationSort = 0;

    protected static ?string $recordTitleAttribute = 'email';

    /**
     * The only customer columns an admin can ever load. password,
     * remember_token and any future credential column are excluded at
     * the query level, not just hidden in the view.
     *
     * @var list<string>
     */
    public const PROFILE_COLUMNS = [
        'users.id',
        'users.name',
        'users.email',
        'users.email_verified_at',
        'users.created_at',
        'users.deleted_at',
    ];

    public static function infolist(Schema $schema): Schema
    {
        return CustomerInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CustomersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            OrdersRelationManager::class,
            EntriesRelationManager::class,
            TicketsRelationManager::class,
            WinnersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'view' => ViewCustomer::route('/{record}'),
        ];
    }

    /**
     * List query: the safe projection plus per-customer counts and last
     * activity dates, all computed in SQL as correlated subqueries (one
     * statement per page — no per-row queries). Order counts include
     * soft-deleted orders because they are still customer history.
     */
    public static function getEloquentQuery(): Builder
    {
        return self::withActivity(parent::getEloquentQuery()->select(self::PROFILE_COLUMNS));
    }

    /**
     * Archived customers remain viewable as history; the list's own
     * trashed filter decides whether they appear in the index.
     */
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return self::withActivity(
            parent::getRecordRouteBindingEloquentQuery()
                ->withoutGlobalScopes([SoftDeletingScope::class])
                ->select(self::PROFILE_COLUMNS),
        );
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public static function withActivity(Builder $query): Builder
    {
        return $query
            ->withCount([
                'orders as orders_count' => fn (Builder $q): Builder => $q->withTrashed(),
                'entries as entries_count',
                'tickets as tickets_count',
                'winners as winners_count',
            ])
            ->withMax(['orders as last_order_at' => fn (Builder $q): Builder => $q->withTrashed()], 'created_at')
            ->withMax('entries as last_entry_at', 'created_at');
    }
}
