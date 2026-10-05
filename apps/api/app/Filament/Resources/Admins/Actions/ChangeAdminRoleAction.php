<?php

namespace App\Filament\Resources\Admins\Actions;

use App\Enums\AdminRole;
use App\Filament\Support\AdminRoleBadge;
use App\Models\Admin;
use App\Services\Admin\AdminManagementException;
use App\Services\Admin\AdminManagementService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * Role change. Visibility mirrors AdminPolicy::updateRole and hides the
 * action on your own account; the service refuses self-changes and the
 * last-SuperAdmin demotion regardless of what this button shows.
 */
class ChangeAdminRoleAction
{
    public static function make(): Action
    {
        return Action::make('changeRole')
            ->label('Change role')
            ->icon(Heroicon::OutlinedUserGroup)
            ->color('warning')
            ->authorize('updateRole')
            ->visible(fn (Admin $record): bool => $record->getKey() !== Auth::guard('admin')->id())
            ->modalHeading('Change role')
            ->modalDescription("This will immediately change this admin's permissions.")
            ->modalSubmitActionLabel('Change role')
            ->schema([
                Select::make('role')
                    ->label('New role')
                    ->options(fn (?Admin $record): array => collect(AdminRoleBadge::options())
                        ->reject(fn (string $label, string $value): bool => $record?->role?->value === $value)
                        ->all())
                    ->required()
                    ->native(false),
                Textarea::make('reason')
                    ->label('Reason')
                    ->helperText('Recorded in the admin audit log with your admin ID.')
                    ->required()
                    ->minLength(5)
                    ->maxLength(500),
            ])
            ->action(function (Admin $record, array $data): void {
                /** @var Admin $actor */
                $actor = Auth::guard('admin')->user();

                try {
                    app(AdminManagementService::class)->changeRole(
                        $actor,
                        $record,
                        AdminRole::from($data['role']),
                        trim($data['reason']),
                    );
                } catch (AdminManagementException $e) {
                    Notification::make()->title('Role not changed')->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Role changed')->success()->send();
            });
    }
}
