<?php

namespace App\Enums;

/**
 * Lifecycle of one `promotion_claims` row. `Pending` is transient within
 * a single request (the claim is synchronous — see PromotionClaimService)
 * and should never be observed outside the transaction that creates it;
 * `Failed` is retryable (a later claim() call for the same user/promotion
 * re-uses the same row rather than inserting a second one — the
 * `unique(promotion_id, user_id)` constraint means there is ever only one
 * row per person, and only `Fulfilled` is terminal/immutable).
 */
enum PromotionClaimStatus: string
{
    case Pending = 'pending';
    case Fulfilled = 'fulfilled';
    case Failed = 'failed';
}
