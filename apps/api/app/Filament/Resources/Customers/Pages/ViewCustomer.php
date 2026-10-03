<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * No header actions — see CustomerResource's docblock. Sections are
 * the profile/contact/account infolist, then the read-only relation
 * managers in the order Orders → Entries → Tickets → Winners & claims.
 */
class ViewCustomer extends ViewRecord
{
    protected static string $resource = CustomerResource::class;
}
