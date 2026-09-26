<?php

namespace App\Filament\Resources\Draws\Pages;

use App\Filament\Resources\Draws\DrawResource;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only — no header actions. Running a draw happens from the list
 * page (RunDrawAction), which targets a competition, not a past attempt.
 */
class ViewDraw extends ViewRecord
{
    protected static string $resource = DrawResource::class;

    /**
     * The winning ticket's holder, with the same minimal customer
     * projection as every other admin resource (credentials never
     * selected).
     */
    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load([
            'result.ticket' => fn ($q) => $q->select(['id', 'user_id', 'ticket_number']),
            'result.ticket.user' => fn ($q) => $q->select(['id', 'name', 'email']),
        ]);
    }
}
