<?php

namespace App\Filament\Resources\Admins\Pages;

use App\Filament\Resources\Admins\AdminResource;
use App\Models\Admin;
use App\Services\Admin\AdminManagementService;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Edits name and email only. Role, status and password have their own
 * domain operations and are not on this form.
 */
class EditAdmin extends EditRecord
{
    protected static string $resource = AdminResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    /**
     * Pre-fill only the fields this form edits. Filament would otherwise
     * place every non-hidden column (role, status, timestamps) into the
     * page's Livewire state, which is sent to the browser.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return [
            'name' => $data['name'] ?? null,
            'email' => $data['email'] ?? null,
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Admin $actor */
        $actor = Auth::guard('admin')->user();

        return app(AdminManagementService::class)->updateProfile($actor, $record, [
            'name' => $data['name'] ?? null,
            'email' => $data['email'] ?? null,
        ]);
    }
}
