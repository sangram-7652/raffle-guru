<?php

namespace App\Filament\Resources\Admins\Schemas;

use App\Enums\AdminRole;
use App\Filament\Support\AdminRoleBadge;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Safe administrative facts only. The query behind this page selects an
 * explicit allow-list of columns, so the hash, remember token and any
 * session value cannot be rendered even by mistake.
 */
class AdminInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Administrator')
                    ->columnSpan(2)
                    ->columns(2)
                    ->components([
                        TextEntry::make('name')->label('Name')->weight('medium'),
                        TextEntry::make('email')->label('Email')->copyable(),
                        TextEntry::make('role')
                            ->label('Role')
                            ->badge()
                            ->formatStateUsing(fn (AdminRole $state): string => AdminRoleBadge::label($state))
                            ->color(fn (AdminRole $state): string => AdminRoleBadge::color($state)),
                        TextEntry::make('must_change_password')
                            ->label('Password')
                            ->badge()
                            ->formatStateUsing(fn (bool $state): string => $state ? 'Change required' : 'Set')
                            ->color(fn (bool $state): string => $state ? 'warning' : 'gray'),
                        TextEntry::make('is_active')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(fn (bool $state): string => $state ? 'Active' : 'Inactive')
                            ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                    ]),
                Section::make('Activity')
                    ->columnSpan(1)
                    ->components([
                        TextEntry::make('last_login_at')
                            ->label('Last login')
                            ->dateTime()
                            ->placeholder('Never recorded'),
                        TextEntry::make('created_at')->label('Created')->dateTime(),
                        TextEntry::make('updated_at')->label('Updated')->dateTime(),
                    ]),
            ]);
    }
}
