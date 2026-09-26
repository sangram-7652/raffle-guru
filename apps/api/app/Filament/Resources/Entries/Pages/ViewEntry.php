<?php

namespace App\Filament\Resources\Entries\Pages;

use App\Filament\Resources\Entries\EntryResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * No header actions — see EntryResource's docblock.
 */
class ViewEntry extends ViewRecord
{
    protected static string $resource = EntryResource::class;
}
