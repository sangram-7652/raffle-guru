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

class ActivateAdminAction
{
    public static function make(): Action
    {
        return Action::make('activate')
            ->label('Activate')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->authorize('activate')
            ->visible(fn (Admin $record): bool => ! $record->is_active)
            ->modalHeading('Activate admin')
            ->modalDescription('This will allow this admin to access the admin panel again.')
            ->modalSubmitActionLabel('Activate')
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
                    app(AdminManagementService::class)->activate($actor, $record, trim($data['reason']));
                } catch (AdminManagementException $e) {
                    Notification::make()->title('Not activated')->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Admin activated')->success()->send();
            });
    }
}
