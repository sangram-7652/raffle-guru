<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Resources\Competitions\CompetitionResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Ticket;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * The customer's allocated tickets, read-only. Ticket numbers are
 * immutable and there is no correction or reallocation operation here
 * or anywhere in the customer administration. Ticket rows carry no
 * status column in the domain, so none is displayed. Gated by
 * TicketPolicy (ticket.view). Paginated in SQL: a customer can hold
 * many tickets.
 */
class TicketsRelationManager extends RelationManager
{
    protected static string $relationship = 'tickets';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return 'Tickets';
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Gate::allows('viewAny', Ticket::class);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('ticket_number')
            ->columns([
                TextColumn::make('ticket_number')
                    ->label('Ticket no.')
                    ->weight('medium')
                    ->sortable()
                    ->url(fn (Ticket $record): string => TicketResource::getUrl('view', ['record' => $record])),
                TextColumn::make('competition.title')
                    ->label('Competition')
                    ->url(fn (Ticket $record): string => CompetitionResource::getUrl('view', ['record' => $record->competition_id]))
                    ->wrap(),
                TextColumn::make('order.reference')
                    ->label('Order')
                    ->url(fn (Ticket $record): string => OrderResource::getUrl('view', ['record' => $record->order_id])),
                TextColumn::make('allocated_at')
                    ->label('Allocated')
                    ->dateTime()
                    ->sortable(),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'competition' => fn ($q) => $q->withTrashed()->select(['id', 'title', 'slug']),
                'order' => fn ($q) => $q->withTrashed()->select(['id', 'reference']),
            ]))
            ->defaultSort('ticket_number');
    }
}
