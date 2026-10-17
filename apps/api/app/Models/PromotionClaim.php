<?php

namespace App\Models;

use App\Enums\PromotionClaimStatus;
use Database\Factories\PromotionClaimFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One row per (promotion, user) pair, ever — see the
 * unique(promotion_id, user_id) constraint on the migration, and
 * App\Services\Promotions\PromotionClaimService, the only writer.
 * `Pending`/`Failed` are mutable (a failed attempt may be retried,
 * re-using this same row — see PromotionClaimStatus); once `Fulfilled`
 * a real ticket exists and this row is frozen, same immutability
 * posture as AdminAuditLog/DrawAuditLog for their own domains.
 */
#[Fillable(['promotion_id', 'user_id', 'newsletter_subscriber_id', 'order_id', 'entry_id', 'status', 'failure_reason'])]
class PromotionClaim extends Model
{
    /** @use HasFactory<PromotionClaimFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (PromotionClaim $claim): void {
            if ($claim->getOriginal('status') === PromotionClaimStatus::Fulfilled) {
                throw new LogicException('A fulfilled promotion claim is immutable.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PromotionClaimStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Promotion, $this>
     */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<NewsletterSubscriber, $this>
     */
    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(NewsletterSubscriber::class, 'newsletter_subscriber_id');
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Entry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(Entry::class);
    }
}
