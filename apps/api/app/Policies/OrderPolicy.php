<?php

namespace App\Policies;

use App\Enums\AdminAbility;
use App\Models\Admin;
use App\Models\Order;
use App\Policies\Concerns\GuardsAdminActor;

/**
 * Orders are read-only for every admin role in P11 — there is
 * deliberately no create/update/delete method here, matching the
 * approved role matrix (neither role may mutate orders directly).
 */
class OrderPolicy
{
    use GuardsAdminActor;

    public function viewAny(Admin $admin): bool
    {
        return $admin->hasAbility(AdminAbility::OrderView);
    }

    public function view(Admin $admin, Order $order): bool
    {
        return $admin->hasAbility(AdminAbility::OrderView);
    }
}
