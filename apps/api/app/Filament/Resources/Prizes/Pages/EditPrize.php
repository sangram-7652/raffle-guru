<?php

namespace App\Filament\Resources\Prizes\Pages;

use App\Filament\Resources\Prizes\PrizeResource;
use App\Models\Prize;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

/**
 * No force-delete — see PrizeResource's docblock.
 */
class EditPrize extends EditRecord
{
    protected static string $resource = PrizeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make()
                ->visible(fn (Prize $record): bool => PrizeResource::prizeSetIsEditable($record)),
            RestoreAction::make()
                ->visible(fn (Prize $record): bool => $record->trashed() && PrizeResource::prizeSetIsEditable($record)),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Neither is dehydrated by PrizeForm on edit; dropped defensively
        // because both are in Prize's $fillable.
        unset($data['competition_id'], $data['currency']);

        return $data;
    }
}
