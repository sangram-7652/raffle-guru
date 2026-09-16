<?php

namespace App\Policies;

use App\Enums\AdminAbility;
use App\Models\Admin;
use App\Models\WinnerClaim;
use App\Policies\Concerns\GuardsAdminActor;

/**
 * Gates calling WinnerClaimService for a given transition — the
 * service's own domain matrix (see WinnerClaimException) still decides
 * whether verify/approve/reject is actually valid for a claim's current
 * state. An ability being granted here is necessary, not sufficient.
 */
class WinnerClaimPolicy
{
    use GuardsAdminActor;

    public function viewAny(Admin $admin): bool
    {
        return $admin->hasAbility(AdminAbility::ClaimView);
    }

    public function view(Admin $admin, WinnerClaim $claim): bool
    {
        return $admin->hasAbility(AdminAbility::ClaimView);
    }

    public function verify(Admin $admin, WinnerClaim $claim): bool
    {
        return $admin->hasAbility(AdminAbility::ClaimVerify);
    }

    public function approve(Admin $admin, WinnerClaim $claim): bool
    {
        return $admin->hasAbility(AdminAbility::ClaimApprove);
    }

    public function reject(Admin $admin, WinnerClaim $claim): bool
    {
        return $admin->hasAbility(AdminAbility::ClaimReject);
    }
}
