<?php

namespace App\Policies;

use App\Enums\AdminAbility;
use App\Models\Admin;
use App\Models\WinnerAuditLog;
use App\Policies\Concerns\GuardsAdminActor;

/**
 * Gates the audit.view ability against the P10 winner/claim/fulfilment
 * audit trail. Super-Admin-only per the P11 role matrix — Operations
 * gets no audit.view ability. This log is immutable/append-only at the
 * model layer already; there is no mutation method to gate here.
 */
class WinnerAuditLogPolicy
{
    use GuardsAdminActor;

    public function viewAny(Admin $admin): bool
    {
        return $admin->hasAbility(AdminAbility::AuditView);
    }

    public function view(Admin $admin, WinnerAuditLog $log): bool
    {
        return $admin->hasAbility(AdminAbility::AuditView);
    }
}
