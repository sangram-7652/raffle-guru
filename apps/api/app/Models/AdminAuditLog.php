<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Immutable, append-only record of admin-account mutations (P11.12).
 * Written only by App\Services\Admin\AdminManagementService, in the same
 * transaction as the change it records. Update and delete are refused
 * at the model layer; the table has no updated_at column.
 *
 * Never store a password, password hash, token, or session value here.
 */
#[Fillable(['actor_admin_id', 'target_admin_id', 'event', 'metadata'])]
class AdminAuditLog extends Model
{
    public const EVENT_CREATED = 'admin.created';

    public const EVENT_UPDATED = 'admin.updated';

    public const EVENT_ROLE_CHANGED = 'admin.role_changed';

    public const EVENT_ACTIVATED = 'admin.activated';

    public const EVENT_DEACTIVATED = 'admin.deactivated';

    public const EVENT_PASSWORD_RESET = 'admin.password_reset';

    public const EVENT_PASSWORD_CHANGED = 'admin.password_changed';

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Admin audit records are immutable.');
        });

        static::deleting(function (): void {
            throw new LogicException('Admin audit records are immutable.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'actor_admin_id');
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'target_admin_id');
    }
}
