<?php

namespace App\Filament\Resources\Admins;

use App\Filament\Resources\Admins\Pages\CreateAdmin;
use App\Filament\Resources\Admins\Pages\EditAdmin;
use App\Filament\Resources\Admins\Pages\ListAdmins;
use App\Filament\Resources\Admins\Pages\ViewAdmin;
use App\Filament\Resources\Admins\RelationManagers\AuditRelationManager;
use App\Filament\Resources\Admins\Schemas\AdminForm;
use App\Filament\Resources\Admins\Schemas\AdminInfolist;
use App\Filament\Resources\Admins\Tables\AdminsTable;
use App\Filament\Support\AdminGate;
use App\Models\Admin;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * P11.12 — staff (admin) account management.
 *
 * Every capability is decided by AdminPolicy (AdminView to see the
 * roster, AdminManage to create, edit, change role, activate or
 * deactivate). Operations holds neither, so it sees no admin accounts.
 * Filament shows or hides controls with those same policy calls, but
 * the visibility is never the authorization: every mutation goes through
 * AdminManagementService, which re-authorizes and enforces the
 * last-SuperAdmin and self-management rules under a row lock.
 *
 * There is no delete. Admin accounts are security and audit actors, so
 * they are deactivated (is_active), never removed.
 */
class AdminResource extends Resource
{
    protected static ?string $model = Admin::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 0;

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * Columns an admin-management screen may ever load. password,
     * remember_token and any future credential column are excluded at
     * the query, not just hidden in the view.
     *
     * @var list<string>
     */
    public const SAFE_COLUMNS = [
        'admins.id',
        'admins.name',
        'admins.email',
        'admins.role',
        'admins.is_active',
        'admins.last_login_at',
        'admins.created_at',
        'admins.updated_at',
    ];

    public static function form(Schema $schema): Schema
    {
        return AdminForm::configureEdit($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AdminInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AdminsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            AuditRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAdmins::route('/'),
            'create' => CreateAdmin::route('/create'),
            'view' => ViewAdmin::route('/{record}'),
            'edit' => EditAdmin::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->select(self::SAFE_COLUMNS);
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()->select(self::SAFE_COLUMNS);
    }

    public static function canCreate(): bool
    {
        return AdminGate::allows('create', Admin::class);
    }

    public static function canEdit(Model $record): bool
    {
        return AdminGate::allows('update', $record);
    }

    // No hard delete, ever: deactivation (is_active) is the lifecycle.
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canForceDelete(Model $record): bool
    {
        return false;
    }

    public static function canForceDeleteAny(): bool
    {
        return false;
    }

    public static function canRestore(Model $record): bool
    {
        return false;
    }

    public static function canRestoreAny(): bool
    {
        return false;
    }

    public static function canReplicate(Model $record): bool
    {
        return false;
    }

    public static function canReorder(): bool
    {
        return false;
    }
}
