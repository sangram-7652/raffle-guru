<?php

namespace App\Policies;

use App\Enums\AdminAbility;
use App\Models\Admin;
use App\Models\Prize;
use App\Policies\Concerns\GuardsAdminActor;

class PrizePolicy
{
    use GuardsAdminActor;

    public function viewAny(Admin $admin): bool
    {
        return $admin->hasAbility(AdminAbility::PrizeView);
    }

    public function view(Admin $admin, Prize $prize): bool
    {
        return $admin->hasAbility(AdminAbility::PrizeView);
    }

    public function create(Admin $admin): bool
    {
        return $admin->hasAbility(AdminAbility::PrizeCreate);
    }

    public function update(Admin $admin, Prize $prize): bool
    {
        return $admin->hasAbility(AdminAbility::PrizeUpdate);
    }

    public function delete(Admin $admin, Prize $prize): bool
    {
        return $admin->hasAbility(AdminAbility::PrizeDelete);
    }

    /**
     * See CompetitionPolicy::restore() — same reasoning, including why
     * forceDelete()/forceDeleteAny() are deliberately absent.
     */
    public function restore(Admin $admin, Prize $prize): bool
    {
        return $admin->hasAbility(AdminAbility::PrizeDelete);
    }
}
