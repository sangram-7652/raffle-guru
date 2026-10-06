<?php

namespace App\Models;

use App\Enums\AdminAbility;
use App\Enums\AdminRole;
use Database\Factories\AdminFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * The staff identity — a completely separate authentication boundary
 * from App\Models\User (customers). Authenticated only via the `admin`
 * guard (config/auth.php), never the `web` guard the storefront uses.
 * See the P11 architecture plan §1/§3 for why this is a dedicated
 * table/model rather than an `is_admin` flag on `users`.
 */
#[Fillable(['name', 'email', 'password', 'role', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class Admin extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<AdminFactory> */
    use HasFactory, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => AdminRole::class,
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'session_version' => 'integer',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * Session key holding the session_version an admin signed in under.
     * Written at login; compared on every admin request.
     */
    public const SESSION_VERSION_KEY = 'admin_session_version';

    /**
     * Admin-account audit events where this admin is the subject (P11.12).
     *
     * @return HasMany<AdminAuditLog, $this>
     */
    public function auditLogsAsTarget(): HasMany
    {
        return $this->hasMany(AdminAuditLog::class, 'target_admin_id');
    }

    /**
     * Admin-account audit events this admin performed (P11.12).
     *
     * @return HasMany<AdminAuditLog, $this>
     */
    public function auditLogsAsActor(): HasMany
    {
        return $this->hasMany(AdminAuditLog::class, 'actor_admin_id');
    }

    /**
     * Server-side gate on panel access itself — deactivating an admin
     * (is_active = false) must immediately lock them out even if their
     * session cookie is still otherwise valid. This is checked on every
     * panel request, not just at login.
     *
     * Touching `$this->role` here (not just `is_active`) is deliberate,
     * not decorative (P11.3 §14 "future unsupported roles fail closed"):
     * `role` is a strict backed enum cast, so a row whose `role` column
     * holds a value outside {super_admin, operations} — only reachable
     * by bypassing Eloquent — throws \ValueError the moment it's
     * accessed. Before this line existed, canAccessPanel() never read
     * `role` at all, so with today's resource-less dashboard nothing on
     * the page would touch it either, and such an admin would silently
     * reach the panel shell instead of being denied. This is the same
     * fail-closed behavior Admin::hasAbility() already relies on,
     * reused at the earliest checkpoint rather than inventing a second
     * access mechanism.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active && $this->role instanceof AdminRole;
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === AdminRole::SuperAdmin;
    }

    /**
     * The single authorization primitive every Policy in app/Policies
     * is built on (P11.2) — deliberately re-checks is_active here, not
     * just at login/canAccessPanel(): a long-lived Livewire/panel
     * session must lose every ability the instant an admin is
     * deactivated, not just be refused the next full page load.
     */
    public function hasAbility(AdminAbility $ability): bool
    {
        // A forced password change fails closed for every ability. The only
        // thing such an admin may do is complete that change (see
        // EnforceAdminSession), so no policy can grant anything in the meantime.
        return $this->is_active
            && ! $this->must_change_password
            && in_array($ability, $this->role->abilities(), true);
    }
}
