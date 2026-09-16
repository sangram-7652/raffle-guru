<?php

namespace App\Policies;

use App\Enums\AdminAbility;
use App\Models\Admin;
use App\Models\Competition;
use App\Policies\Concerns\GuardsAdminActor;

/**
 * Gates admin access to competitions. This is an authorization check
 * only — "is this admin allowed to attempt a lifecycle transition" —
 * not a business-state check. CompetitionLifecycleService still owns
 * whether a given transition is actually valid for a given
 * competition's current status and must be called regardless of what
 * this policy allows (P11.2 §7).
 */
class CompetitionPolicy
{
    use GuardsAdminActor;

    public function viewAny(Admin $admin): bool
    {
        return $admin->hasAbility(AdminAbility::CompetitionView);
    }

    public function view(Admin $admin, Competition $competition): bool
    {
        return $admin->hasAbility(AdminAbility::CompetitionView);
    }

    public function create(Admin $admin): bool
    {
        return $admin->hasAbility(AdminAbility::CompetitionCreate);
    }

    public function update(Admin $admin, Competition $competition): bool
    {
        return $admin->hasAbility(AdminAbility::CompetitionUpdate);
    }

    public function delete(Admin $admin, Competition $competition): bool
    {
        return $admin->hasAbility(AdminAbility::CompetitionDelete);
    }

    /**
     * Gates calling CompetitionLifecycleService at all — not which
     * transition. See class docblock.
     */
    public function transitionLifecycle(Admin $admin, Competition $competition): bool
    {
        return $admin->hasAbility(AdminAbility::CompetitionLifecycle);
    }
}
