<?php

namespace App\Filament\Resources\Tickets;

use App\Filament\Resources\Concerns\ReadOnlyResource;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Filament\Resources\Tickets\Schemas\TicketInfolist;
use App\Filament\Resources\Tickets\Tables\TicketsTable;
use App\Models\Ticket;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Read-only operational view of P7 tickets (P11.7). Tickets are
 * immutable once TicketAllocationService allocates them — number,
 * owner, competition, entry and order never change, and P7 exposes no
 * correction operation — so this resource is list + view only, no
 * form, every mutating capability false (ReadOnlyResource). Viewing is
 * gated by TicketPolicy (ticket.view).
 *
 * Ticket numbers are unique per COMPETITION (tickets.unique
 * (competition_id, ticket_number)), not globally, so they are always
 * shown alongside their competition.
 */
class TicketResource extends Resource
{
    use ReadOnlyResource;

    protected static ?string $model = Ticket::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static string|UnitEnum|null $navigationGroup = 'Tickets & Draws';

    protected static ?int $navigationSort = 2;

    public static function infolist(Schema $schema): Schema
    {
        return TicketInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TicketsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTickets::route('/'),
            'view' => ViewTicket::route('/{record}'),
        ];
    }

    /**
     * Minimal projections, one query per relation per page; customer
     * credentials are never selected.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'user' => fn ($q) => $q->select(['id', 'name', 'email']),
            'competition' => fn ($q) => $q->withTrashed()->select(['id', 'title', 'slug', 'status']),
            'order' => fn ($q) => $q->withTrashed()->select(['id', 'reference', 'status']),
        ]);
    }
}
