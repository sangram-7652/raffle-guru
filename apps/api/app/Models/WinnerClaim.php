<?php

namespace App\Models;

use App\Enums\WinnerClaimStatus;
use Database\Factories\WinnerClaimFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The winner's single claim (P10) — see docs/WINNER_CLAIM_P10.md "Claim
 * lifecycle". At most one row per winner, ever (database unique
 * constraint on winner_id). Written exclusively by
 * App\Services\Winner\WinnerClaimService — never a direct ->update()
 * from a controller.
 */
#[Fillable(['winner_id', 'status', 'submitted_at', 'verified_at', 'rejected_at', 'rejection_reason', 'notes', 'metadata'])]
class WinnerClaim extends Model
{
    /** @use HasFactory<WinnerClaimFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => WinnerClaimStatus::class,
            'submitted_at' => 'datetime',
            'verified_at' => 'datetime',
            'rejected_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Winner, $this>
     */
    public function winner(): BelongsTo
    {
        return $this->belongsTo(Winner::class);
    }
}
