<?php

namespace App\Filament\Resources\Tickets\Pages;

use App\Filament\Resources\Tickets\TicketResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * No header actions — see TicketResource's docblock.
 */
class ViewTicket extends ViewRecord
{
    protected static string $resource = TicketResource::class;
}
