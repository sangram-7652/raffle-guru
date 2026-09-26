<?php

namespace App\Filament\Resources\Entries\Pages;

use App\Filament\Resources\Entries\EntryResource;
use Filament\Resources\Pages\ListRecords;

class ListEntries extends ListRecords
{
    protected static string $resource = EntryResource::class;
}
