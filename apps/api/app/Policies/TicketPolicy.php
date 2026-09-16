<?php

namespace App\Policies;

use App\Enums\AdminAbility;
use App\Models\Admin;
use App\Models\Ticket;
use App\Policies\Concerns\GuardsAdminActor;

class TicketPolicy
{
    use GuardsAdminActor;

    public function viewAny(Admin $admin): bool
    {
        return $admin->hasAbility(AdminAbility::TicketView);
    }

    public function view(Admin $admin, Ticket $ticket): bool
    {
        return $admin->hasAbility(AdminAbility::TicketView);
    }
}
