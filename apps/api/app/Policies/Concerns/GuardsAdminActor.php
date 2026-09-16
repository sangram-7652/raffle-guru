<?php

namespace App\Policies\Concerns;

use App\Models\Admin;

/**
 * Every admin Policy's ability methods type-hint `Admin $admin` — that
 * type-hint is enforced by Laravel's Gate only in the sense that a
 * customer's App\Models\User (non-null, so Gate\Gate::canBeCalledWithUser()
 * happily calls the method anyway) would otherwise hit a raw PHP
 * TypeError instead of a clean denial. This `before()` hook — Gate's
 * documented, guest-callable short-circuit — turns that into an
 * ordinary `false` for anyone who isn't an Admin (customers, guests,
 * any other future Authenticatable) before the specific ability method
 * ever runs, so "customer identity denied" and "unauthenticated
 * denied" are guaranteed at one shared choke point instead of repeated
 * per policy.
 */
trait GuardsAdminActor
{
    public function before(mixed $actor, string $ability): ?bool
    {
        return $actor instanceof Admin ? null : false;
    }
}
