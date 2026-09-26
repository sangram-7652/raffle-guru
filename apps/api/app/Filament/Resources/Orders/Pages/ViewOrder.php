<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * No header actions — see OrderResource's docblock.
 */
class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;
}
