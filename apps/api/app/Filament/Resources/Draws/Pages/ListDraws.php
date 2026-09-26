<?php

namespace App\Filament\Resources\Draws\Pages;

use App\Enums\CompetitionStatus;
use App\Filament\Resources\Draws\Actions\RunDrawAction;
use App\Filament\Resources\Draws\DrawResource;
use App\Models\Competition;
use Filament\Resources\Pages\ListRecords;

class ListDraws extends ListRecords
{
    protected static string $resource = DrawResource::class;

    protected function getHeaderActions(): array
    {
        return [
            RunDrawAction::make(),
        ];
    }

    public function getSubheading(): ?string
    {
        $pending = Competition::query()->where('status', CompetitionStatus::DrawPending)->count();

        return $pending === 0
            ? 'Each row is one draw attempt. No competition is awaiting a draw.'
            : "Each row is one draw attempt. {$pending} competition(s) awaiting a draw.";
    }
}
