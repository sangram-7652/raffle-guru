<?php

namespace App\Filament\Resources\Admins\Tables;

use App\Enums\AdminRole;
use App\Filament\Resources\Admins\AdminResource;
use App\Filament\Support\AdminRoleBadge;
use App\Filament\Support\CreatedBetweenFilter;
use App\Models\Admin;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Paginated and filtered in SQL. Searches are prefix matches, the same
 * convention as the customer and order tables, so nothing scans for
 * %term% anywhere in the roster. The list needs no relations, so there
 * is no per-row query.
 */
class AdminsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                TextColumn::make('name')
                    ->weight('medium')
                    ->url(fn (Admin $record): string => AdminResource::getUrl('view', ['record' => $record]))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where('admins.name', 'like', $search.'%')),
                TextColumn::make('email')
                    ->copyable()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where('admins.email', 'like', $search.'%')),
                TextColumn::make('role')
                    ->badge()
                    ->formatStateUsing(fn (AdminRole $state): string => AdminRoleBadge::label($state))
                    ->color(fn (AdminRole $state): string => AdminRoleBadge::color($state)),
                TextColumn::make('is_active')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Active' : 'Inactive')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                TextColumn::make('must_change_password')
                    ->label('Password')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Change required' : 'Set')
                    ->color(fn (bool $state): string => $state ? 'warning' : 'gray'),
                TextColumn::make('last_login_at')
                    ->label('Last login')
                    ->dateTime()
                    ->placeholder('Never recorded'),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('role')
                    ->options(AdminRoleBadge::options()),
                TernaryFilter::make('is_active')
                    ->label('Status')
                    ->placeholder('All admins')
                    ->trueLabel('Active')
                    ->falseLabel('Inactive'),
                CreatedBetweenFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
