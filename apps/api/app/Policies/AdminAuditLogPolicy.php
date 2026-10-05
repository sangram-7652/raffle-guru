<?php

namespace App\Policies;

use App\Enums\AdminAbility;
use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Policies\Concerns\GuardsAdminActor;

/**
 * Gates the audit.view ability against admin-account audit history.
 * Super-Admin-only per the P11 role matrix; Operations holds no
 * audit.view. Append-only at the model layer, so no mutation method.
 */
class AdminAuditLogPolicy
{
    use GuardsAdminActor;

    public function viewAny(Admin $admin): bool
    {
        return $admin->hasAbility(AdminAbility::AuditView);
    }

    public function view(Admin $admin, AdminAuditLog $log): bool
    {
        return $admin->hasAbility(AdminAbility::AuditView);
    }
}
