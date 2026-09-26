<?php

namespace App\Filament\Resources\Competitions\Pages;

use App\Filament\Resources\Competitions\CompetitionResource;
use App\Filament\Resources\Competitions\Pages\Concerns\NormalizesSkillQuestion;
use App\Filament\Support\CompetitionPreLaunch;
use App\Models\Competition;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

/**
 * No ForceDeleteAction — see CompetitionResource's docblock.
 */
class EditCompetition extends EditRecord
{
    use NormalizesSkillQuestion;

    protected static string $resource = CompetitionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make()
                ->visible(fn (Competition $record): bool => CompetitionPreLaunch::is($record)),
            RestoreAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Neither is a dehydrated form field; dropped defensively because
        // both are in Competition's $fillable. Status belongs to
        // CompetitionLifecycleService, and currency is fixed at create.
        unset($data['status'], $data['currency']);

        return $this->normalizeSkillQuestion($data);
    }
}
