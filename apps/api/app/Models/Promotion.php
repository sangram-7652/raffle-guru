<?php

namespace App\Models;

use App\Enums\PromotionClaimStatus;
use Database\Factories\PromotionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A free-ticket giveaway tied to exactly one competition — see
 * docs/EMAIL_CAPTURE.md "Option 1 proposal". Claims are created and
 * validated exclusively by App\Services\Promotions\PromotionClaimService,
 * which holds this row's lock for the whole decision (enabled/window/
 * capacity) — nothing here enforces those rules by itself; this model
 * is data plus read-only convenience checks for the UI/admin.
 */
#[Fillable(['name', 'slug', 'competition_id', 'is_enabled', 'starts_at', 'ends_at', 'total_capacity'])]
class Promotion extends Model
{
    /** @use HasFactory<PromotionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Competition, $this>
     */
    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    /**
     * @return HasMany<PromotionClaim, $this>
     */
    public function claims(): HasMany
    {
        return $this->hasMany(PromotionClaim::class);
    }

    /**
     * Display/read-only convenience — PromotionClaimService re-checks
     * all three conditions itself, under its own row lock, rather than
     * trusting this.
     */
    public function isOpenAt(Carbon $at): bool
    {
        if (! $this->is_enabled) {
            return false;
        }

        if ($this->starts_at !== null && $at->lt($this->starts_at)) {
            return false;
        }

        if ($this->ends_at !== null && $at->gt($this->ends_at)) {
            return false;
        }

        return true;
    }

    /**
     * Non-locking count — fine for display (Filament table, public "is
     * this open" read), never used as the enforcement boundary for
     * capacity itself (see PromotionClaimService).
     */
    public function fulfilledClaimsCount(): int
    {
        return $this->claims()->where('status', PromotionClaimStatus::Fulfilled)->count();
    }
}
