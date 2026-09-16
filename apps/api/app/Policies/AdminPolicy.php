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
 * Deliberately NOT implemented here: a "last remaining active Super
 * Admin can't be demoted/deactivated" safeguard. P11.2 is authorization
 * only — no admin-management mutation code path exists yet (no
 * Filament AdminResource, no AdminManagementService) for such a
 * safeguard to attach to, and building one now would mean inventing
 * untested mutation infrastructure ahead of its own milestone. This
 * belongs in whichever future milestone adds the actual mutation
 * (as a domain/service-level check, not a Filament confirmation
 * modal), tracked as a known limitation below.
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
