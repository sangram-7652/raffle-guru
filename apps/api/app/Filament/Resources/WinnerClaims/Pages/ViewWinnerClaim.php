<?php

namespace App\Filament\Resources\WinnerClaims\Pages;

use App\Filament\Resources\WinnerClaims\Actions\ClaimTransitionAction;
use App\Filament\Resources\WinnerClaims\WinnerClaimResource;
use Filament\Resources\Pages\ViewRecord;

class ViewWinnerClaim extends ViewRecord
{
    protected static string $resource = WinnerClaimResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ClaimTransitionAction::startVerification(),
            ClaimTransitionAction::verify(),
            ClaimTransitionAction::approve(),
            ClaimTransitionAction::reject(),
        ];
    }
}
