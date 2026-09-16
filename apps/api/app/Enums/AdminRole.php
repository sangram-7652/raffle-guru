<?php

namespace App\Enums;

/**
 * Staff role on the `admins` table — see docs P11 architecture plan
 * "Authorization model". Deliberately a fixed enum, not a
 * roles/permissions package: two roles today, a small and well-
 * understood ability set per role, enforced entirely server-side via
 * Policies (never by hiding a Filament button). Plain string column,
 * not MySQL ENUM — same convention as every other status/type column
 * in this schema (CompetitionStatus, OrderStatus, WinnerStatus, ...).
 *
 * Support/Finance were considered (see the architecture plan) and
 * deliberately deferred — nothing in this phase's approved scope needs
 * more than Super Admin vs. Operations.
 */
enum AdminRole: string
{
    case SuperAdmin = 'super_admin';
    case Operations = 'operations';
}
