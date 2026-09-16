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
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * Server-side gate on panel access itself — deactivating an admin
     * (is_active = false) must immediately lock them out even if their
     * session cookie is still otherwise valid. This is checked on every
     * panel request, not just at login.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active;
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
        return $this->is_active && in_array($ability, $this->role->abilities(), true);
    }
}
