<?php

namespace App\Policies;

use App\Enums\AdminAbility;
use App\Models\Admin;
use App\Models\Payment;
use App\Policies\Concerns\GuardsAdminActor;

/**
 * Payments are READ-ONLY in the admin panel for every role (see the P11
 * architecture plan's payment rule) — deliberately no create/update/
 * delete/markPaid/markFailed/markRefunded method here. PaymentService
 * and the real payment/webhook flow remain the only way payment status
 * changes; this policy exists only to gate visibility.
 */
class PaymentPolicy
{
    use GuardsAdminActor;

    public function viewAny(Admin $admin): bool
    {
        return $admin->hasAbility(AdminAbility::PaymentView);
    }

    public function view(Admin $admin, Payment $payment): bool
    {
        return $admin->hasAbility(AdminAbility::PaymentView);
    }
}
