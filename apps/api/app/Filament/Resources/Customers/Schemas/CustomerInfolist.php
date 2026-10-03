<?php

namespace App\Filament\Resources\Customers\Schemas;

use App\Models\User;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Read-only customer profile. Shows only the columns the resource's
 * safe projection loads (id, name, email, verification and account
 * dates) plus the SQL-computed activity counts. Credentials are never
 * selected, so they cannot be rendered here even by mistake.
 *
 * The order/entry/ticket/winner history lives in the relation managers
 * below this infolist, not in the profile, so a customer with a long
 * history never inflates the page's first render.
 */
class CustomerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Customer profile')
                    ->columnSpan(2)
                    ->columns(3)
                    ->components([
                        TextEntry::make('name')
                            ->label('Name')
                            ->weight('medium'),
                        TextEntry::make('id')
                            ->label('Customer')
                            ->formatStateUsing(fn (int $state): string => "#{$state}"),
                        TextEntry::make('created_at')
                            ->label('Registered')
                            ->dateTime(),
                    ]),

                Section::make('Activity')
                    ->columnSpan(1)
                    ->columns(2)
                    ->components([
                        TextEntry::make('orders_count')->label('Orders')->numeric(),
                        TextEntry::make('entries_count')->label('Entries')->numeric(),
                        TextEntry::make('tickets_count')->label('Tickets')->numeric(),
                        TextEntry::make('winners_count')->label('Wins')->numeric(),
                        TextEntry::make('last_order_at')->label('Last order')->dateTime()->placeholder('—'),
                        TextEntry::make('last_entry_at')->label('Last entry')->dateTime()->placeholder('—'),
                    ]),

                Section::make('Contact')
                    ->columnSpan(2)
                    ->columns(2)
                    ->components([
                        TextEntry::make('email')
                            ->label('Email')
                            ->copyable(),
                        TextEntry::make('email_verified_at')
                            ->label('Email verified')
                            ->dateTime()
                            ->placeholder('Not verified'),
                    ]),

                Section::make('Account')
                    ->columnSpan(1)
                    ->components([
                        TextEntry::make('account_status')
                            ->label('Account status')
                            ->state(fn (User $record): string => $record->trashed() ? 'Archived' : 'Active')
                            ->badge()
                            ->color(fn (string $state): string => $state === 'Archived' ? 'gray' : 'success'),
                        TextEntry::make('deleted_at')
                            ->label('Archived on')
                            ->dateTime()
                            ->placeholder('—'),
                    ]),
            ]);
    }
}
