<?php

namespace App\Policies;

use App\Enums\AdminAbility;
use App\Models\Admin;
use App\Models\Promotion;
use App\Policies\Concerns\GuardsAdminActor;

class PromotionPolicy
{
    use GuardsAdminActor;

    public function viewAny(Admin $admin): bool
    {
        return $admin->hasAbility(AdminAbility::PromotionView);
    }

    public function view(Admin $admin, Promotion $promotion): bool
    {
        return $admin->hasAbility(AdminAbility::PromotionView);
    }

    public function create(Admin $admin): bool
    {
        return $admin->hasAbility(AdminAbility::PromotionManage);
    }

    public function update(Admin $admin, Promotion $promotion): bool
    {
        return $admin->hasAbility(AdminAbility::PromotionManage);
    }

    /**
     * No delete anywhere in the UI (see PromotionResource) — defined
     * for completeness/table-testability only, same as other domains'
     * policies that gate an action nothing currently exposes.
     */
    public function delete(Admin $admin, Promotion $promotion): bool
    {
        return $admin->hasAbility(AdminAbility::PromotionManage);
    }
}
