<?php

namespace App\Filament\Resources\Admins\Actions;

use App\Models\Admin;
use App\Services\Admin\AdminManagementException;
use App\Services\Admin\AdminManagementService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * SuperAdmin sets a temporary password for another admin. Visibility mirrors
 * AdminPolicy::resetPassword (hidden on your own account). The service
 * re-authorizes, validates, and writes the audit row.
 *
 * The temporary password appears in this form only until submission. It is
 * never shown after the action, audited, or logged.
 */
class ResetAdminPasswordAction
{
    public static function make(): Action
    {
        return Action::make('resetPassword')
            ->label('Reset password')
            ->icon(Heroicon::OutlinedKey)
            ->color('warning')
            ->authorize('resetPassword')
            ->visible(fn (Admin $record): bool => $record->getKey() !== Auth::guard('admin')->id())
            ->modalHeading('Reset password')
            ->modalDescription('This signs the admin out of every session and requires a new password at their next sign-in. Share the temporary password through a secure channel. It will not be shown again.')
            ->modalSubmitActionLabel('Reset password')
            ->schema([
                TextInput::make('temporary_password')
                    ->label('Temporary password')
                    ->password()
                    ->revealable()
                    ->required()
                    ->rule(Password::defaults())
                    ->confirmed()
                    ->suffixAction(
                        Action::make('generate')
                            ->label('Generate')
                            ->icon(Heroicon::OutlinedArrowPath)
                            ->action(function (Set $set): void {
                                $generated = Str::password(20);
                                $set('temporary_password', $generated);
                                $set('temporary_password_confirmation', $generated);
                            }),
                    ),
                TextInput::make('temporary_password_confirmation')
                    ->label('Confirm temporary password')
                    ->password()
                    ->revealable()
                    ->required()
                    ->dehydrated(false),
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
                    app(AdminManagementService::class)->resetPassword(
                        $actor,
                        $record,
                        $data['temporary_password'],
                        trim($data['reason']),
                    );
                } catch (AdminManagementException $e) {
                    Notification::make()->title('Password not reset')->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title('Temporary password set')
                    ->body('The admin must change it at their next sign-in.')
                    ->success()
                    ->send();
            });
    }
}
