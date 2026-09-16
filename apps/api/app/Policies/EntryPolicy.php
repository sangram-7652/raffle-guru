<?php

namespace App\Policies;

use App\Enums\AdminAbility;
use App\Models\Admin;
use App\Models\Entry;
use App\Policies\Concerns\GuardsAdminActor;

class EntryPolicy
{
    use GuardsAdminActor;

    public function viewAny(Admin $admin): bool
    {
        return $admin->hasAbility(AdminAbility::EntryView);
    }

    public function view(Admin $admin, Entry $entry): bool
    {
        return $admin->hasAbility(AdminAbility::EntryView);
    }
}
