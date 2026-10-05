<?php

namespace App\Filament\Support;

use App\Enums\AdminRole;
use Illuminate\Support\Str;

/**
 * Display helpers for AdminRole. The role list itself comes from the
 * enum; nothing here defines which roles exist or what they may do.
 */
final class AdminRoleBadge
{
    public static function label(?AdminRole $role): ?string
    {
        return $role === null ? null : Str::headline($role->value);
    }

    public static function color(?AdminRole $role): string
    {
        return match ($role) {
            AdminRole::SuperAdmin => 'danger',
            AdminRole::Operations => 'info',
            null => 'gray',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(AdminRole::cases())
            ->mapWithKeys(fn (AdminRole $role): array => [$role->value => self::label($role)])
            ->all();
    }
}
