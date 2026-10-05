<?php

namespace App\Filament\Resources\Admins\Pages;

use App\Filament\Resources\Admins\Actions\ActivateAdminAction;
use App\Filament\Resources\Admins\Actions\ChangeAdminRoleAction;
use App\Filament\Resources\Admins\Actions\DeactivateAdminAction;
use App\Filament\Resources\Admins\AdminResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewAdmin extends ViewRecord
{
    protected static string $resource = AdminResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            ChangeAdminRoleAction::make(),
            ActivateAdminAction::make(),
            DeactivateAdminAction::make(),
        ];
    }
}
