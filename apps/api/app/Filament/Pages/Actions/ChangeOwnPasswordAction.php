<?php

namespace App\Filament\Pages\Actions;

use App\Models\Admin;
use App\Services\Admin\AdminManagementException;
use App\Services\Admin\AdminManagementService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Completes a forced password change. Rate limited per admin, separately
 * from sign-in throttling, so guessing the temporary password here is
 * bounded. On success the admin is signed out everywhere and must sign in
 * again with the new password.
 */
class ChangeOwnPasswordAction
{
    private const MAX_ATTEMPTS = 5;

    public static function make(): Action
    {
        return Action::make('changeOwnPassword')
            ->label('Change password')
            ->icon(Heroicon::OutlinedKey)
            ->color('primary')
            ->modalHeading('Change your password')
            ->modalDescription('You will be signed out after this and must sign in again.')
            ->modalSubmitActionLabel('Change password')
            ->schema([
                TextInput::make('current_password')
                    ->label('Temporary password')
                    ->password()
                    ->revealable()
                    ->required(),
                TextInput::make('password')
                    ->label('New password')
                    ->password()
                    ->revealable()
                    ->required()
                    ->rule(Password::defaults())
                    ->confirmed(),
                TextInput::make('password_confirmation')
                    ->label('Confirm new password')
                    ->password()
                    ->revealable()
                    ->required()
                    ->dehydrated(false),
            ])
            ->successRedirectUrl(fn (): string => route('filament.admin.auth.login'))
            ->action(function (array $data): void {
                /** @var Admin $admin */
                $admin = Auth::guard('admin')->user();
                $key = 'admin-forced-password:'.$admin->getKey();

                if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
                    Notification::make()
                        ->title('Too many attempts')
                        ->body('Wait a minute before trying again.')
                        ->danger()
                        ->send();

                    throw new Halt;
                }

                try {
                    app(AdminManagementService::class)->completeForcedPasswordChange(
                        $admin,
                        $data['current_password'],
                        $data['password'],
                    );
                } catch (ValidationException $e) {
                    RateLimiter::hit($key, 60);

                    throw $e;
                } catch (AdminManagementException $e) {
                    Notification::make()->title('Password not changed')->body($e->getMessage())->danger()->send();

                    throw new Halt;
                }

                RateLimiter::clear($key);

                Auth::guard('admin')->logout();
                session()->invalidate();
                session()->regenerateToken();

                Notification::make()
                    ->title('Password changed')
                    ->body('Sign in with your new password.')
                    ->success()
                    ->send();
            });
    }
}
