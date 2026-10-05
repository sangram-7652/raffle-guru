<?php

namespace App\Filament\Support;

use App\Models\Admin;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Policy checks for the staff identity, always against the `admin`
 * guard. Gate::allows() alone resolves the user through the DEFAULT guard,
 * which only happens to be `admin` while Filament's panel middleware is
 * running. Resolving the actor explicitly keeps a check correct when it
 * is called from anywhere else, and a guest or customer is always denied.
 */
final class AdminGate
{
    public static function allows(string $ability, mixed $subject = null): bool
    {
        $admin = Auth::guard('admin')->user();

        if (! $admin instanceof Admin) {
            return false;
        }

        return Gate::forUser($admin)->allows($ability, $subject);
    }
}
