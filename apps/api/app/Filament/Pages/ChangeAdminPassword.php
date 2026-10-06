<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Actions\ChangeOwnPasswordAction;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;

/**
 * The only page a forced-password-change admin can reach (P11.12
 * hardening). It is available ONLY while must_change_password is set, so no
 * normal admin can open it, and every other admin page redirects here until
 * the change is made (EnforceAdminSession).
 *
 * Hidden from navigation. Completing the change signs the admin out, so they
 * sign in again with the new password.
 */
class ChangeAdminPassword extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Change your password';

    public static function canAccess(): bool
    {
        return Auth::guard('admin')->user()?->must_change_password === true;
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'An administrator set a temporary password for your account. Choose a new password to continue. '
            .'You will then be signed out and need to sign in again.';
    }

    protected function getHeaderActions(): array
    {
        return [
            ChangeOwnPasswordAction::make(),
        ];
    }
}
