<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Resources\Competitions\CompetitionResource;
use App\Filament\Resources\Entries\EntryResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Entry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * The customer's competition entries, read-only. Requested quantity is
 * the entry's own quantity; allocated is the count of tickets it
 * actually produced (both persisted by P7, neither recalculated).
 * Gated by EntryPolicy (entry.view).
 */
class EntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'entries';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return 'Entries';
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Gate::allows('viewAny', Entry::class);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('Entry')
                    ->formatStateUsing(fn (int $state): string => "#{$state}")
                    ->weight('medium')
                    ->url(fn (Entry $record): string => EntryResource::getUrl('view', ['record' => $record])),
                TextColumn::make('competition.title')
                    ->label('Competition')
                    ->url(fn (Entry $record): string => CompetitionResource::getUrl('view', ['record' => $record->competition_id]))
                    ->wrap(),
                TextColumn::make('order.reference')
                    ->label('Order')
                    ->url(fn (Entry $record): string => OrderResource::getUrl('view', ['record' => $record->order_id])),
                TextColumn::make('quantity')
                    ->label('Tickets requested')
                    ->numeric(),
                TextColumn::make('tickets_count')
                    ->label('Tickets allocated')
                    ->numeric(),
                TextColumn::make('created_at')
                    ->label('Entered')
                    ->dateTime()
                    ->sortable(),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->withCount('tickets')
                ->with([
                    'competition' => fn ($q) => $q->withTrashed()->select(['id', 'title', 'slug']),
                    'order' => fn ($q) => $q->withTrashed()->select(['id', 'reference']),
                ]))
            ->defaultSort('id', 'desc')
            ->recordUrl(fn (Entry $record): string => EntryResource::getUrl('view', ['record' => $record]));
    }
}
