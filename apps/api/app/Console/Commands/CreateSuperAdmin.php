<?php

namespace App\Console\Commands;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Services\Admin\AdminManagementException;
use App\Services\Admin\AdminManagementService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * P11.14 — the one safe way to create the very first SuperAdmin. Before
 * this command existed, bootstrapping required direct DB/tinker access
 * (see docs/ADMIN_USERS_P11.md "First SuperAdmin bootstrap"), exactly
 * the kind of manual intervention every other admin mutation in this
 * domain is designed to avoid.
 *
 * A thin presentation-layer adapter only: every actual rule (no
 * SuperAdmin may already exist, password strength, email uniqueness,
 * the audit trail) lives in
 * App\Services\Admin\AdminManagementService::bootstrapSuperAdmin(), the
 * same place every other admin invariant in this domain lives. This
 * command never writes to the `admins` table directly.
 */
class CreateSuperAdmin extends Command
{
    /**
     * @var string
     */
    protected $signature = 'admin:create-super';

    /**
     * @var string
     */
    protected $description = 'Create the first Super Admin account (run once, during initial deployment)';

    public function handle(AdminManagementService $admins): int
    {
        // Fast, friendly path only — never the security boundary. Same
        // philosophy as Filament hiding a denied action: this just saves
        // the operator from typing a name/email/password that would be
        // refused anyway. The actual refusal is the locked re-check
        // inside bootstrapSuperAdmin(), which is what makes this safe
        // even if two operators run this at the same moment.
        if (Admin::query()->where('role', AdminRole::SuperAdmin)->exists()) {
            $this->error('A Super Admin already exists. Bootstrap has already been completed.');

            return self::FAILURE;
        }

        $this->info('No Super Admin exists yet — creating the first one.');

        $name = (string) $this->ask('Name');
        $email = (string) $this->ask('Email');
        $password = (string) $this->secret('Password');
        $confirmation = (string) $this->secret('Confirm password');

        if ($password !== $confirmation) {
            $this->error('Password confirmation does not match.');

            return self::FAILURE;
        }

        try {
            $admin = $admins->bootstrapSuperAdmin([
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ]);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->error("{$field}: {$message}");
                }
            }

            return self::FAILURE;
        } catch (AdminManagementException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Super Admin created: {$admin->email} (id {$admin->id}).");

        return self::SUCCESS;
    }
}
