<?php

namespace App\Policies;

use App\Enums\AdminAbility;
use App\Models\Admin;
use App\Models\Draw;
use App\Policies\Concerns\GuardsAdminActor;

/**
 * `run` is a class-level ability (no Draw instance exists until the
 * draw actually runs) — it gates "is this admin allowed to invoke
 * DrawService::run() at all", not "is this competition eligible right
 * now". DrawEligibilityService/DrawService still decide eligibility,
 * randomness and the resulting Draw record; this policy never enters
 * that decision (P11.2 §6/§7 — no second RNG, no manual winning
 * ticket, authorization is not business validation).
 */
class DrawPolicy
{
    use GuardsAdminActor;

    public function viewAny(Admin $admin): bool
    {
        return $admin->hasAbility(AdminAbility::DrawView);
    }

    public function view(Admin $admin, Draw $draw): bool
    {
        return $admin->hasAbility(AdminAbility::DrawView);
    }

    public function run(Admin $admin): bool
    {
        return $admin->hasAbility(AdminAbility::DrawRun);
    }
}
