<?php

namespace App\Enums;

/**
 * Distinguishes a normal paid checkout from a promotional (free,
 * `total = 0`) order created by App\Services\Promotions\PromotionClaimService
 * — see docs/EMAIL_CAPTURE.md "Option 1 proposal". The single place
 * "is this order real revenue or a giveaway" is ever recorded; never
 * inferred from `total === 0` alone (a £0 data error should never be
 * silently read as a legitimate giveaway).
 */
enum OrderSource: string
{
    case Purchase = 'purchase';
    case Promotional = 'promotional';
}
