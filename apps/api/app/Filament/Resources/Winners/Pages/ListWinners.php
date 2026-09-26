<?php

namespace App\Filament\Resources\Winners\Pages;

use App\Filament\Resources\Winners\WinnerResource;
use Filament\Resources\Pages\ListRecords;

class ListWinners extends ListRecords
{
    protected static string $resource = WinnerResource::class;
}
