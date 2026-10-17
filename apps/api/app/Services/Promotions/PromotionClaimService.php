<?php

namespace App\Services\Promotions;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PromotionClaimStatus;
use App\Models\Competition;
use App\Models\Entry;
use App\Models\NewsletterSubscriber;
use App\Models\Order;
use App\Models\Promotion;
use App\Models\PromotionClaim;
use App\Models\User;
use App\Services\Tickets\CapacityExceededException;
use App\Services\Tickets\PerUserLimitExceededException;
use App\Services\Tickets\TicketAllocationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The only place a PromotionClaim is created or resolved — see
 * docs/EMAIL_CAPTURE.md "Option 1 proposal" for the full design and the
 * decisions it was built against. Deliberately does NOT modify
 * TicketAllocationService: the $0 `Order` this creates already
 * satisfies that service's one gate (`status === Paid`), so the
 * "smallest explicit extension" turns out to be calling its existing
 * public method, never touching it.
 */
class PromotionClaimService
{
    public function __construct(
        private readonly TicketAllocationService $allocation,
    ) {}

    /**
     * Idempotent: safe to call more than once for the same user/
     * promotion. A second call after a `Fulfilled` claim returns that
     * same row unchanged — no second ticket. A second call after a
     * `Failed` claim retries (e.g. an admin raised capacity, or the
     * competition re-opened) by re-using the same row, never inserting
     * a second one — the unique(promotion_id, user_id) constraint makes
     * that the only possible outcome even under a race.
     *
     * @throws PromotionNotAvailableException
     */
    public function claim(User $user, Promotion $promotion): PromotionClaim
    {
        $claim = $this->resolve($user, $promotion, attempt: 0);

        if ($claim->status === PromotionClaimStatus::Failed) {
            // Thrown OUTSIDE the transaction that wrote this row — the
            // 'failed' claim (with its reason) is already committed and
            // auditable regardless of what the caller does with this
            // exception.
            throw new PromotionNotAvailableException($claim->failure_reason ?? 'This offer is not available.');
        }

        return $claim;
    }

    /**
     * Always returns a committed PromotionClaim (Fulfilled or Failed —
     * never leaves one Pending) and never throws for an ordinary
     * ineligibility outcome; only a genuinely unexpected database error
     * escapes. Keeping this transaction-boundary logic exception-free
     * is deliberate: throwing here would roll back the very 'failed'
     * audit row this method exists to persist.
     */
    private function resolve(User $user, Promotion $promotion, int $attempt): PromotionClaim
    {
        try {
            return DB::transaction(function () use ($user, $promotion) {
                // Locks every claim attempt on THIS promotion into a
                // strict one-at-a-time queue — the same role the
                // Competition row lock plays in TicketAllocationService.
                // Everything read below (existing claim, capacity count)
                // happens only after acquiring this lock, so none of it
                // is reading a stale/pre-lock snapshot.
                /** @var Promotion $locked */
                $locked = Promotion::query()->lockForUpdate()->findOrFail($promotion->id);

                $claim = PromotionClaim::query()
                    ->where('promotion_id', $locked->id)
                    ->where('user_id', $user->id)
                    ->first();

                if ($claim !== null && $claim->status === PromotionClaimStatus::Fulfilled) {
                    return $claim;
                }

                $claim ??= PromotionClaim::create([
                    'promotion_id' => $locked->id,
                    'user_id' => $user->id,
                    'status' => PromotionClaimStatus::Pending,
                ]);

                $reason = $this->ineligibilityReason($locked);

                if ($reason !== null) {
                    $claim->update(['status' => PromotionClaimStatus::Failed, 'failure_reason' => $reason]);
                    Log::info('promotion.claim.failed', ['promotion_id' => $locked->id, 'user_id' => $user->id, 'reason' => $reason]);

                    return $claim;
                }

                // Already confirmed purchasable by ineligibilityReason()
                // above, in this same transaction — plain find() here,
                // not a second purchasable()-scoped query.
                $competition = Competition::query()->findOrFail($locked->competition_id);

                try {
                    [$order, $entry] = $this->allocateFreeTicket($user, $competition);
                } catch (CapacityExceededException|PerUserLimitExceededException) {
                    $reason = 'The competition for this offer is full.';
                    $claim->update(['status' => PromotionClaimStatus::Failed, 'failure_reason' => $reason]);
                    Log::info('promotion.claim.failed', ['promotion_id' => $locked->id, 'user_id' => $user->id, 'reason' => $reason]);

                    return $claim;
                }

                $subscriber = NewsletterSubscriber::subscribe($user->email);

                $claim->update([
                    'newsletter_subscriber_id' => $subscriber->id,
                    'order_id' => $order->id,
                    'entry_id' => $entry?->id,
                    'status' => PromotionClaimStatus::Fulfilled,
                    'failure_reason' => null,
                ]);

                Log::info('promotion.claim.fulfilled', [
                    'promotion_id' => $locked->id,
                    'user_id' => $user->id,
                    'order_id' => $order->id,
                    'entry_id' => $entry?->id,
                ]);

                return $claim->refresh();
            });
        } catch (QueryException $e) {
            // Duplicate-key on orders.reference (see OrderController's
            // identical retry) — astronomically rare, same reasoning.
            if ($attempt < 3 && str_contains($e->getMessage(), 'orders_reference_unique')) {
                return $this->resolve($user, $promotion, $attempt + 1);
            }

            // Duplicate-key on promotion_claims'
            // unique(promotion_id, user_id) — the hard safety net. Given
            // the Promotion row lock above fully serializes every claim
            // attempt on this promotion, this should be unreachable in
            // practice; if it ever fires, another request already holds
            // the one row for this user — re-read it rather than error.
            if (str_contains($e->getMessage(), 'promotion_claims_promotion_id_user_id_unique')) {
                return PromotionClaim::query()
                    ->where('promotion_id', $promotion->id)
                    ->where('user_id', $user->id)
                    ->firstOrFail();
            }

            throw $e;
        }
    }

    private function ineligibilityReason(Promotion $promotion): ?string
    {
        if (! $promotion->is_enabled) {
            return 'This offer is not currently enabled.';
        }

        $now = now();

        if ($promotion->starts_at !== null && $now->lt($promotion->starts_at)) {
            return 'This offer has not started yet.';
        }

        if ($promotion->ends_at !== null && $now->gt($promotion->ends_at)) {
            return 'This offer has ended.';
        }

        // Locking read — see TicketAllocationService's identical
        // reasoning for why a plain SELECT COUNT(*) is not safe here
        // under MySQL's REPEATABLE READ isolation.
        $fulfilledCount = PromotionClaim::query()
            ->where('promotion_id', $promotion->id)
            ->where('status', PromotionClaimStatus::Fulfilled)
            ->lockForUpdate()
            ->count();

        if ($fulfilledCount >= $promotion->total_capacity) {
            return 'This offer has reached its claim limit.';
        }

        if (Competition::query()->purchasable()->whereKey($promotion->competition_id)->doesntExist()) {
            return 'The competition for this offer is no longer open for entry.';
        }

        return null;
    }

    /**
     * Creates the $0 Order/OrderItem and allocates it, all inside its
     * own nested transaction (a savepoint, since this always runs
     * already inside resolve()'s outer transaction) — so a thrown
     * CapacityExceededException/PerUserLimitExceededException rolls
     * back exactly this Order/OrderItem/Entry/Ticket unit, leaving no
     * orphaned rows, WITHOUT rolling back the outer transaction's
     * promotion lock and claim-row write. That's what lets the caller
     * still commit a `Failed` claim with a reason after this throws.
     *
     * @return array{0: Order, 1: ?Entry}
     */
    private function allocateFreeTicket(User $user, Competition $competition): array
    {
        return DB::transaction(function () use ($user, $competition) {
            $order = Order::create([
                'user_id' => $user->id,
                'reference' => $this->generateReference(),
                'status' => OrderStatus::Paid,
                'source' => OrderSource::Promotional,
                'currency' => $competition->currency,
                'subtotal' => 0,
                'total' => 0,
            ]);

            $order->items()->create([
                'competition_id' => $competition->id,
                'prize_id' => $competition->prizes()->value('id'),
                'competition_title' => $competition->title,
                'quantity' => 1,
                'unit_price' => 0,
                'currency' => $competition->currency,
                'line_total' => 0,
            ]);

            // Synchronous, not via the OrderPaid event/queue — the
            // caller needs to know the ticket genuinely exists before
            // the HTTP response says so (see docs/EMAIL_CAPTURE.md).
            // Still re-validates the competition's real capacity/
            // per-user limit under its own lock: a promotional claim
            // cannot push a competition past what a paid order could.
            $this->allocation->allocateOrder($order);

            return [$order, $order->entries()->first()];
        });
    }

    private function generateReference(): string
    {
        return 'RG-'.random_int(100000, 999999);
    }
}
