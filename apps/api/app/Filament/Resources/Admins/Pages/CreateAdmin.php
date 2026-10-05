<?php

namespace App\Filament\Resources\Admins\Pages;

use App\Filament\Resources\Admins\AdminResource;
use App\Filament\Resources\Admins\Schemas\AdminForm;
use App\Models\Admin;
use App\Services\Admin\AdminManagementService;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Creation goes through AdminManagementService::create(), which owns the
 * password hashing (the Admin model's `hashed` cast), email uniqueness,
 * role validation and the audit row. The plaintext password is never
 * audited, logged or returned.
 */
class CreateAdmin extends CreateRecord
{
    protected static string $resource = AdminResource::class;

    public function form(Schema $schema): Schema
    {
        return AdminForm::configureCreate($schema);
    }

    protected function handleRecordCreation(array $data): Model
    {
        /** @var Admin $actor */
        $actor = Auth::guard('admin')->user();

        return app(AdminManagementService::class)->create($actor, $data);
    }
}
