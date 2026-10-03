<?php

namespace App\Policies;

use App\Enums\AdminAbility;
use App\Models\Admin;
use App\Models\PrizeDelivery;
use App\Policies\Concerns\GuardsAdminActor;

/**
 * Gates PrizeFulfilmentService access. `assignPrize` is a class-level
 * ability — it gates the P10 ambiguous multi-prize assignment method
 * that P11.2 does not itself implement (that's a later milestone's
 * domain-service work); this policy only establishes that Operations
 * and Super Admin are both permitted to invoke it once it exists. No
 * fake courier integration or generated tracking numbers are gated
 * here or anywhere else — PrizeFulfilmentService owns that boundary.
 */
class PrizeDeliveryPolicy
{
    use GuardsAdminActor;

    public function viewAny(Admin $admin): bool
    {
        return $admin->hasAbility(AdminAbility::FulfilmentView);
    }

    public function view(Admin $admin, PrizeDelivery $delivery): bool
    {
        return $admin->hasAbility(AdminAbility::FulfilmentView);
    }

    public function advance(Admin $admin, PrizeDelivery $delivery): bool
    {
        return $admin->hasAbility(AdminAbility::FulfilmentAdvance);
    }

    /** Starting fulfilment (claim_approved -> delivery_pending + the delivery). */
    public function start(Admin $admin): bool
    {
        return $admin->hasAbility(AdminAbility::FulfilmentAdvance);
    }

    public function assignPrize(Admin $admin): bool
    {
        return $admin->hasAbility(AdminAbility::FulfilmentAssignPrize);
    }
}
