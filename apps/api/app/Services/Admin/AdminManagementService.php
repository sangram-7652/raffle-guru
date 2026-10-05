<?php

namespace App\Services\Admin;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\AdminAuditLog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * The only code path that mutates admin accounts (P11.12). Filament
 * actions and forms call this; they never write to Admin directly.
 *
 * Every mutation does, in one transaction:
 *   1. lock the whole admins table in id order (deterministic, so two
 *      concurrent requests serialize instead of deadlocking);
 *   2. re-read the acting admin from that locked set and authorize
 *      against their CURRENT state, so a deactivation that committed
 *      a moment ago is honoured;
 *   3. enforce the invariants below against the locked state;
 *   4. write the change and its AdminAuditLog row together.
 *
 * Invariants (all enforced here, never only in the UI):
 *   - At least one ACTIVE SuperAdmin always exists. Any deactivation or
 *     demotion that would leave zero is refused.
 *   - An admin cannot deactivate or demote THEMSELVES, even when other
 *     SuperAdmins exist, so no one can lock themselves out by accident.
 *   - Emails are unique (checked under the lock, with the unique index
 *     as the backstop).
 *   - Only AdminRole values are accepted; mass assignment is limited to
 *     the explicit keys each method takes.
 *
 * Locking the whole table is deliberate: it is a staff roster (tens of
 * rows), and it keeps the invariant check and the write in one serial
 * section without gap-lock reasoning. Revisit if the roster ever grows
 * large.
 */
class AdminManagementService
{
    /**
     * @param  array{name: string, email: string, role: string, password: string, is_active?: bool}  $data
     */
    public function create(Admin $actor, array $data): Admin
    {
        $validated = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', Rule::enum(AdminRole::class)],
            'password' => ['required', 'string', Password::defaults()],
            'is_active' => ['sometimes', 'boolean'],
        ])->validate();

        $email = trim($validated['email']);
        $role = AdminRole::from($validated['role']);
        $isActive = (bool) ($validated['is_active'] ?? true);

        return DB::transaction(function () use ($actor, $validated, $email, $role, $isActive): Admin {
            [$freshActor, $admins] = $this->lockAll($actor);

            Gate::forUser($freshActor)->authorize('create', Admin::class);
            $this->assertEmailAvailable($admins, $email);

            $admin = Admin::query()->create([
                'name' => trim($validated['name']),
                'email' => $email,
                // Admin's `password` cast hashes this (Laravel's configured
                // hasher). The plaintext is never audited or returned.
                'password' => $validated['password'],
                'role' => $role,
                'is_active' => $isActive,
            ]);

            $this->audit($freshActor, $admin, AdminAuditLog::EVENT_CREATED, [
                'role' => $role->value,
                'is_active' => $isActive,
            ]);

            return $admin;
        });
    }

    /**
     * Safe fields only: name and email. Role, status and password have
     * their own operations.
     *
     * @param  array{name?: string, email?: string}  $data
     */
    public function updateProfile(Admin $actor, Admin $target, array $data): Admin
    {
        $validated = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
        ])->validate();

        $name = trim($validated['name']);
        $email = trim($validated['email']);

        return DB::transaction(function () use ($actor, $target, $name, $email): Admin {
            [$freshActor, $locked, $admins] = $this->lockFor($actor, $target);

            Gate::forUser($freshActor)->authorize('update', $locked);

            if ($email !== $locked->email) {
                $this->assertEmailAvailable($admins, $email, exceptId: $locked->getKey());
            }

            $locked->fill(['name' => $name, 'email' => $email]);
            $changed = array_keys($locked->getDirty());

            if ($changed === []) {
                return $locked;
            }

            $locked->save();

            $this->audit($freshActor, $locked, AdminAuditLog::EVENT_UPDATED, ['changed' => $changed]);

            return $locked;
        });
    }

    public function changeRole(Admin $actor, Admin $target, AdminRole $to, string $reason): Admin
    {
        $reason = $this->reason($reason);

        return DB::transaction(function () use ($actor, $target, $to, $reason): Admin {
            [$freshActor, $locked, $admins] = $this->lockFor($actor, $target);

            Gate::forUser($freshActor)->authorize('updateRole', $locked);

            if ($freshActor->getKey() === $locked->getKey()) {
                throw new AdminManagementException('You cannot change your own role.');
            }

            if ($locked->role === $to) {
                throw new AdminManagementException("This admin is already {$to->name}.");
            }

            if ($this->isActiveSuperAdmin($locked) && $to !== AdminRole::SuperAdmin) {
                $this->assertAnotherActiveSuperAdminRemains($admins, $locked);
            }

            $from = $locked->role;
            $locked->role = $to;
            $locked->save();

            $this->audit($freshActor, $locked, AdminAuditLog::EVENT_ROLE_CHANGED, [
                'from' => $from->value,
                'to' => $to->value,
                'reason' => $reason,
            ]);

            return $locked;
        });
    }

    public function activate(Admin $actor, Admin $target, ?string $reason = null): Admin
    {
        $reason = $this->optionalReason($reason);

        return DB::transaction(function () use ($actor, $target, $reason): Admin {
            [$freshActor, $locked] = $this->lockFor($actor, $target);

            Gate::forUser($freshActor)->authorize('activate', $locked);

            if ($locked->is_active) {
                throw new AdminManagementException('This admin is already active.');
            }

            $locked->is_active = true;
            $locked->save();

            $this->audit($freshActor, $locked, AdminAuditLog::EVENT_ACTIVATED, array_filter([
                'reason' => $reason,
            ]));

            return $locked;
        });
    }

    public function deactivate(Admin $actor, Admin $target, string $reason): Admin
    {
        $reason = $this->reason($reason);

        return DB::transaction(function () use ($actor, $target, $reason): Admin {
            [$freshActor, $locked, $admins] = $this->lockFor($actor, $target);

            Gate::forUser($freshActor)->authorize('deactivate', $locked);

            if ($freshActor->getKey() === $locked->getKey()) {
                throw new AdminManagementException('You cannot deactivate your own account.');
            }

            if (! $locked->is_active) {
                throw new AdminManagementException('This admin is already inactive.');
            }

            if ($this->isActiveSuperAdmin($locked)) {
                $this->assertAnotherActiveSuperAdminRemains($admins, $locked);
            }

            $locked->is_active = false;
            $locked->save();

            $this->audit($freshActor, $locked, AdminAuditLog::EVENT_DEACTIVATED, ['reason' => $reason]);

            return $locked;
        });
    }

    /**
     * Locks every admin row in id order. Must be called inside a
     * transaction. Returns [acting admin, locked target, all admins].
     *
     * @return array{0: Admin, 1: Admin, 2: Collection<int, Admin>}
     */
    private function lockFor(Admin $actor, Admin $target): array
    {
        [$freshActor, $admins] = $this->lockAll($actor);

        $locked = $admins->get($target->getKey())
            ?? throw (new ModelNotFoundException)->setModel(Admin::class, [$target->getKey()]);

        return [$freshActor, $locked, $admins];
    }

    /**
     * Locks every admin row in id order. Must be called inside a
     * transaction. Returns [acting admin as currently stored, all admins].
     *
     * @return array{0: Admin, 1: Collection<int, Admin>}
     */
    private function lockAll(Admin $actor): array
    {
        /** @var Collection<int, Admin> $admins */
        $admins = Admin::query()
            ->select(['id', 'name', 'email', 'role', 'is_active'])
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $freshActor = $admins->get($actor->getKey())
            ?? throw new AuthorizationException('The acting admin no longer exists.');

        return [$freshActor, $admins];
    }

    private function isActiveSuperAdmin(Admin $admin): bool
    {
        return $admin->is_active && $admin->role === AdminRole::SuperAdmin;
    }

    /**
     * @param  Collection<int, Admin>  $admins
     */
    private function assertAnotherActiveSuperAdminRemains(Collection $admins, Admin $subject): void
    {
        $others = $admins->filter(fn (Admin $a): bool => $a->getKey() !== $subject->getKey()
            && $this->isActiveSuperAdmin($a));

        if ($others->isEmpty()) {
            throw new AdminManagementException(
                'This would leave no active Super Admin. At least one active Super Admin must always remain.',
            );
        }
    }

    /**
     * Emails compare case-insensitively here, matching the MySQL
     * collation the unique index uses, so the check behaves the same on
     * SQLite in tests.
     *
     * @param  Collection<int, Admin>  $admins
     */
    private function assertEmailAvailable(Collection $admins, string $email, ?int $exceptId = null): void
    {
        $taken = $admins->contains(fn (Admin $a): bool => $a->getKey() !== $exceptId
            && strcasecmp($a->email, $email) === 0);

        if ($taken) {
            throw ValidationException::withMessages([
                'email' => 'An admin with this email address already exists.',
            ]);
        }
    }

    private function reason(string $reason): string
    {
        $trimmed = trim($reason);

        if (mb_strlen($trimmed) < 5 || mb_strlen($trimmed) > 500) {
            throw ValidationException::withMessages([
                'reason' => 'Enter a reason between 5 and 500 characters.',
            ]);
        }

        return $trimmed;
    }

    private function optionalReason(?string $reason): ?string
    {
        $trimmed = $reason === null ? null : trim($reason);

        if ($trimmed === null || $trimmed === '') {
            return null;
        }

        if (mb_strlen($trimmed) > 500) {
            throw ValidationException::withMessages([
                'reason' => 'The reason may not be longer than 500 characters.',
            ]);
        }

        return $trimmed;
    }

    /**
     * @param  array<string, mixed>  $metadata  Structured, non-secret context only.
     */
    private function audit(Admin $actor, Admin $target, string $event, array $metadata = []): void
    {
        AdminAuditLog::query()->create([
            'actor_admin_id' => $actor->getKey(),
            'target_admin_id' => $target->getKey(),
            'event' => $event,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }
}
