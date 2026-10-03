<?php

namespace App\Filament\Resources\Customers\Tables;

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Support\CreatedBetweenFilter;
use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only customer list. Paginated, filtered, searched and sorted in
 * SQL. Counts and last-activity dates come from the resource's
 * correlated subqueries, so the list never loads per-customer relation
 * rows. Phone search is not offered: customer accounts store no phone
 * number (see docs/AUTHENTICATION.md and the users schema).
 *
 * Searches are PREFIX matches, the same convention as the order and
 * entry tables: name and email are not %term% scanned.
 */
class CustomersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->weight('medium')
                    ->url(fn (User $record): string => CustomerResource::getUrl('view', ['record' => $record]))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where('users.name', 'like', $search.'%')),
                TextColumn::make('email')
                    ->copyable()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where('users.email', 'like', $search.'%')),
                TextColumn::make('account_status')
                    ->label('Account')
                    ->state(fn (User $record): string => $record->trashed() ? 'Archived' : 'Active')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Archived' ? 'gray' : 'success'),
                IconColumn::make('email_verified_at')
                    ->label('Email verified')
                    ->boolean()
                    ->state(fn (User $record): bool => $record->email_verified_at !== null),
                TextColumn::make('orders_count')
                    ->label('Orders')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('entries_count')
                    ->label('Entries')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('tickets_count')
                    ->label('Tickets')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('winners_count')
                    ->label('Wins')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('last_order_at')
                    ->label('Last order')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Registered')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                // Default view is active customers only. "Archived" only
                // reveals rows that are already soft-deleted; nothing in
                // this panel can archive or restore one.
                TrashedFilter::make()
                    ->label('Account')
                    ->placeholder('Active')
                    ->trueLabel('Active and archived')
                    ->falseLabel('Archived only'),
                TernaryFilter::make('email_verified')
                    ->label('Email verified')
                    ->placeholder('All customers')
                    ->trueLabel('Verified')
                    ->falseLabel('Unverified')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('users.email_verified_at'),
                        false: fn (Builder $query): Builder => $query->whereNull('users.email_verified_at'),
                    ),
                CreatedBetweenFilter::make(),
                TernaryFilter::make('has_orders')
                    ->label('Orders')
                    ->placeholder('All customers')
                    ->trueLabel('With orders')
                    ->falseLabel('Without orders')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereHas('orders', fn (Builder $o): Builder => $o->withTrashed()),
                        false: fn (Builder $query): Builder => $query->whereDoesntHave('orders', fn (Builder $o): Builder => $o->withTrashed()),
                    ),
                TernaryFilter::make('has_entries')
                    ->label('Entries')
                    ->placeholder('All customers')
                    ->trueLabel('With entries')
                    ->falseLabel('Without entries')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereHas('entries'),
                        false: fn (Builder $query): Builder => $query->whereDoesntHave('entries'),
                    ),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
