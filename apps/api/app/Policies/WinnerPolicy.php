<?php

namespace App\Policies;

use App\Enums\AdminAbility;
use App\Models\Admin;
use App\Models\Winner;
use App\Policies\Concerns\GuardsAdminActor;

/**
 * Winner identity (user/ticket/competition) is derived entirely from
 * the P9/P10 draw result and is never admin-editable — there is
 * deliberately no update/delete method here. `transition` gates calling
 * WinnerLifecycleService at all; the service's own transition matrix
 * still decides whether a specific status change is valid.
 */
class WinnerPolicy
{
    use GuardsAdminActor;

    public function viewAny(Admin $admin): bool
    {
        return $admin->hasAbility(AdminAbility::WinnerView);
    }

    public function view(Admin $admin, Winner $winner): bool
    {
        return $admin->hasAbility(AdminAbility::WinnerView);
    }

    public function transition(Admin $admin, Winner $winner): bool
    {
        return $admin->hasAbility(AdminAbility::WinnerTransition);
    }
}
