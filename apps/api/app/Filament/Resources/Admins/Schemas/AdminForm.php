<?php

namespace App\Filament\Resources\Admins\Schemas;

use App\Enums\AdminRole;
use App\Filament\Support\AdminRoleBadge;
use App\Models\Admin;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Password;

/**
 * Two forms. Creation carries role, status and the initial password.
 * Editing carries only name and email. The form is never the
 * authorization: AdminManagementService validates every field again.
 */
class AdminForm
{
    public static function configureCreate(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Administrator')
                ->columns(2)
                ->components([
                    TextInput::make('name')->required()->maxLength(255),
                    TextInput::make('email')
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->unique(Admin::class, 'email'),
                    Select::make('role')
                        ->label('Role')
                        ->options(AdminRoleBadge::options())
                        ->default(AdminRole::Operations->value)
                        ->required()
                        ->native(false),
                    Toggle::make('is_active')
                        ->label('Active')
                        ->helperText('Inactive admins cannot sign in to the admin panel.')
                        ->default(true),
                ]),
            Section::make('Initial password')
                ->description('Shown once here. It is stored only as a hash and cannot be viewed afterwards. Share it through a secure channel.')
                ->columns(2)
                ->components([
                    TextInput::make('password')
                        ->password()
                        ->revealable()
                        ->required()
                        ->rule(Password::defaults())
                        ->confirmed(),
                    TextInput::make('password_confirmation')
                        ->password()
                        ->revealable()
                        ->required()
                        ->dehydrated(false),
                ]),
        ]);
    }

    public static function configureEdit(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Administrator')
                ->columns(2)
                ->components([
                    TextInput::make('name')->required()->maxLength(255),
                    TextInput::make('email')
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->unique(Admin::class, 'email', ignoreRecord: true),
                ]),
        ]);
    }
}
