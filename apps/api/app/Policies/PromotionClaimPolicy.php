<?php

namespace App\Policies;

use App\Enums\AdminAbility;
use App\Models\Admin;
use App\Models\PromotionClaim;
use App\Policies\Concerns\GuardsAdminActor;

/**
 * Read-only for every admin role — a claim's own lifecycle is written
 * exclusively by PromotionClaimService, never from the admin panel (no
 * "approve"/"reject"/"revoke" action exists; see PromotionClaimService's
 * immutability guard once Fulfilled).
 */
class PromotionClaimPolicy
{
    use GuardsAdminActor;

    public function viewAny(Admin $admin): bool
    {
        return $admin->hasAbility(AdminAbility::PromotionView);
    }

    public function view(Admin $admin, PromotionClaim $claim): bool
    {
        return $admin->hasAbility(AdminAbility::PromotionView);
    }
}
