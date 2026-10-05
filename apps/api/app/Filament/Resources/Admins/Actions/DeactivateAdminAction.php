<?php

namespace App\Filament\Resources\Admins\Actions;

use App\Models\Admin;
use App\Services\Admin\AdminManagementException;
use App\Services\Admin\AdminManagementService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * Deactivation. Hidden on your own account and on accounts that are
 * already inactive. The service re-checks the final-SuperAdmin rule
 * under a lock before anything is written.
 */
class DeactivateAdminAction
{
    public static function make(): Action
    {
        return Action::make('deactivate')
            ->label('Deactivate')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->authorize('deactivate')
            ->visible(fn (Admin $record): bool => $record->is_active
                && $record->getKey() !== Auth::guard('admin')->id())
            ->modalHeading('Deactivate admin')
            ->modalDescription('This will prevent this admin from accessing the admin panel.')
            ->modalSubmitActionLabel('Deactivate')
            ->schema([
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
                    app(AdminManagementService::class)->deactivate($actor, $record, trim($data['reason']));
                } catch (AdminManagementException $e) {
                    Notification::make()->title('Not deactivated')->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Admin deactivated')->success()->send();
            });
    }
}
