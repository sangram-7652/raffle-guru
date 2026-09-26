<?php

namespace App\Filament\Resources\Competitions\Pages;

use App\Filament\Resources\Competitions\CompetitionResource;
use App\Filament\Resources\Competitions\Pages\Concerns\NormalizesSkillQuestion;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCompetition extends CreateRecord
{
    use NormalizesSkillQuestion;

    protected static string $resource = CompetitionResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // `status` is not a form field, so it can't be in $data — but it
        // IS in Competition's $fillable, so drop it defensively rather
        // than trust that. A new competition gets the column default
        // (`draft`); every later status comes only from
        // CompetitionLifecycleService (P11.5 §11).
        unset($data['status']);

        // GBP only — see CompetitionForm's currency field.
        $data['currency'] = 'GBP';

        return $this->normalizeSkillQuestion($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        // Load the database-default status into the model instance so
        // anything rendered after create sees `draft`, not null.
        return parent::handleRecordCreation($data)->refresh();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
