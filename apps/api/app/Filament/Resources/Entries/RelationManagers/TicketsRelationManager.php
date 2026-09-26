<?php

namespace App\Filament\Resources\Entries\RelationManagers;

use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Ticket;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The tickets this entry produced — read-only on every page (forced by
 * isReadOnly(), not just Filament's view-page default): no header,
 * record or bulk actions, no form. Rows link to the ticket's own view
 * page. Paginated in SQL; tickets are listed in number order.
 */
class TicketsRelationManager extends RelationManager
{
    protected static string $relationship = 'tickets';

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
                    ->searchable(query: fn (Builder $query, string $search): Builder => ctype_digit(ltrim($search, '#'))
                        ? $query->where('ticket_number', (int) ltrim($search, '#'))
                        : $query->whereRaw('1 = 0')),
                TextColumn::make('allocated_at')
                    ->label('Allocated')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('ticket_number')
            ->recordUrl(fn (Ticket $record): string => TicketResource::getUrl('view', ['record' => $record]));
    }
}
