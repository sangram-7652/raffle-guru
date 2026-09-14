<?php

namespace App\Models;

use App\Enums\WinnerStatus;
use Database\Factories\WinnerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * The winner lifecycle record (P10) — see docs/WINNER_CLAIM_P10.md. At
 * most one row per competition/draw/draw_result, ever (three
 * independent database-level unique constraints — see the migration).
 * Created exclusively by App\Services\Winner\WinnerService, from
 * DrawResult.ticket_id -> Ticket.user_id — never from HTTP input. Its
 * `status` is written exclusively by
 * App\Services\Winner\WinnerLifecycleService.
 */
#[Fillable([
    'competition_id',
    'draw_id',
    'draw_result_id',
    'ticket_id',
    'user_id',
    'status',
    'contacted_at',
    'verified_at',
    'claim_approved_at',
    'delivery_pending_at',
    'delivered_at',
    'completed_at',
    'metadata',
])]
class Winner extends Model
{
    /** @use HasFactory<WinnerFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => WinnerStatus::class,
            'contacted_at' => 'datetime',
            'verified_at' => 'datetime',
            'claim_approved_at' => 'datetime',
            'delivery_pending_at' => 'datetime',
            'delivered_at' => 'datetime',
            'completed_at' => 'datetime',
            'metadata' => 'array',
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
     * @return BelongsTo<Draw, $this>
     */
    public function draw(): BelongsTo
    {
        return $this->belongsTo(Draw::class);
    }

    /**
     * @return BelongsTo<DrawResult, $this>
     */
    public function drawResult(): BelongsTo
    {
        return $this->belongsTo(DrawResult::class);
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * At most one, ever — see docs/WINNER_CLAIM_P10.md "Claim
     * lifecycle".
     *
     * @return HasOne<WinnerClaim, $this>
     */
    public function claim(): HasOne
    {
        return $this->hasOne(WinnerClaim::class);
    }

    /**
     * @return HasOne<PrizeDelivery, $this>
     */
    public function delivery(): HasOne
    {
        return $this->hasOne(PrizeDelivery::class);
    }

    /**
     * @return HasMany<WinnerAuditLog, $this>
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(WinnerAuditLog::class);
    }
}
