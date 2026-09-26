<?php

namespace App\Filament\Resources\Entries;

use App\Filament\Resources\Concerns\ReadOnlyResource;
use App\Filament\Resources\Entries\Pages\ListEntries;
use App\Filament\Resources\Entries\Pages\ViewEntry;
use App\Filament\Resources\Entries\RelationManagers\TicketsRelationManager;
use App\Filament\Resources\Entries\Schemas\EntryInfolist;
use App\Filament\Resources\Entries\Tables\EntriesTable;
use App\Models\Entry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Read-only operational view of P7 entries (P11.7): who entered which
 * competition, through which order, and which tickets resulted.
 *
 * TicketAllocationService::allocateOrder() is the sole creator of
 * entries and tickets and P7 exposes no correction operation, so this
 * resource offers none: list + view only, no form, every mutating
 * capability false (ReadOnlyResource), and the tickets relation manager
 * is read-only. Viewing is gated by EntryPolicy (entry.view).
 */
class EntryResource extends Resource
{
    use ReadOnlyResource;

    protected static ?string $model = Entry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Tickets & Draws';

    protected static ?int $navigationSort = 1;

    protected static ?string $pluralModelLabel = 'entries';

    public static function infolist(Schema $schema): Schema
    {
        return EntryInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EntriesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            TicketsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEntries::route('/'),
            'view' => ViewEntry::route('/{record}'),
        ];
    }

    /**
     * Minimal projections, one query per relation per page. Customer
     * credentials are never selected; soft-deleted competitions/orders
     * still resolve because entries are history.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(self::relatedProjections());
    }

    /**
     * @return array<string, mixed>
     */
    public static function relatedProjections(): array
    {
        return [
            'user' => fn ($q) => $q->select(['id', 'name', 'email']),
            'competition' => fn ($q) => $q->withTrashed()->select(['id', 'title', 'slug']),
            'order' => fn ($q) => $q->withTrashed()->select(['id', 'reference', 'status', 'currency', 'total']),
        ];
    }
}
