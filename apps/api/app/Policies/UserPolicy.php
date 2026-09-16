<?php

namespace App\Policies;

use App\Enums\AdminAbility;
use App\Models\Admin;
use App\Models\User;
use App\Policies\Concerns\GuardsAdminActor;

/**
 * Governs admin (staff) visibility into customer records — not
 * customer self-authorization, which doesn't exist yet and isn't part
 * of P11. There is deliberately no create/update/delete method: no
 * admin role may mutate a customer account in P11, only view it.
 */
class UserPolicy
{
    use GuardsAdminActor;

    public function viewAny(Admin $admin): bool
    {
        return $admin->hasAbility(AdminAbility::CustomerView);
    }

    public function view(Admin $admin, User $customer): bool
    {
        return $admin->hasAbility(AdminAbility::CustomerView);
    }
}
