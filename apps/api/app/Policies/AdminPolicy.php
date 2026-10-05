<?php

namespace App\Policies;

use App\Enums\AdminAbility;
use App\Models\Admin;
use App\Policies\Concerns\GuardsAdminActor;

/**
 * Admin account management (viewing the roster, creating admins,
 * editing details, changing roles, activating/deactivating) is
 * Super-Admin-only in P11 — Operations gets no admin.manage ability at
 * all (see AdminRole::abilities()), so every method below denies
 * Operations outright rather than needing a per-method role check.
 *
 * The last-active-SuperAdmin invariant is NOT enforced here. Policies
 * answer "may this actor attempt it"; App\Services\Admin\
 * AdminManagementService owns the invariant (under a row lock) so that
 * it holds for every caller, including crafted requests.
 */
class AdminPolicy
{
    use GuardsAdminActor;

    public function viewAny(Admin $admin): bool
    {
        return $admin->hasAbility(AdminAbility::AdminView);
    }

    public function view(Admin $admin, Admin $target): bool
    {
        return $admin->hasAbility(AdminAbility::AdminView);
    }

    public function create(Admin $admin): bool
    {
        return $admin->hasAbility(AdminAbility::AdminManage);
    }

    public function update(Admin $admin, Admin $target): bool
    {
        return $admin->hasAbility(AdminAbility::AdminManage);
    }

    public function updateRole(Admin $admin, Admin $target): bool
    {
        return $admin->hasAbility(AdminAbility::AdminManage);
    }

    public function activate(Admin $admin, Admin $target): bool
    {
        return $admin->hasAbility(AdminAbility::AdminManage);
    }

    public function deactivate(Admin $admin, Admin $target): bool
    {
        return $admin->hasAbility(AdminAbility::AdminManage);
    }
}
