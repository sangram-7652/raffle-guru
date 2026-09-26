<?php

namespace App\Filament\Resources\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * For resources that are an operational window onto records another
 * domain owns (P11.6: orders are P5's, payments are P6's). Every
 * mutating capability is hard-false at the RESOURCE level — not merely
 * "no edit page registered" — so no page, table action, bulk action or
 * crafted Livewire call can reach create/edit/delete/restore through
 * Filament's own authorization, whatever the policy says.
 *
 * Viewing still goes through the model's policy (viewAny/view), which
 * is the only authorization those policies define.
 */
trait ReadOnlyResource
{
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

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

    public static function canReorder(): bool
    {
        return false;
    }

    public static function canReplicate(Model $record): bool
    {
        return false;
    }
}
