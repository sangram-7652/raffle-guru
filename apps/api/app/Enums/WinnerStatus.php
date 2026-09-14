<?php

namespace App\Enums;

/**
 * The winner lifecycle (P10) — see docs/WINNER_CLAIM_P10.md "Winner
 * lifecycle". Plain string column, not MySQL ENUM (same P2 convention
 * as CompetitionStatus/OrderStatus/DrawStatus). Strictly forward-only;
 * the only code path allowed to change this is
 * App\Services\Winner\WinnerLifecycleService.
 */
enum WinnerStatus: string
{
    case PendingContact = 'pending_contact';
    case Contacted = 'contacted';
    case VerificationPending = 'verification_pending';
    case Verified = 'verified';
    case ClaimApproved = 'claim_approved';
    case DeliveryPending = 'delivery_pending';
    case Delivered = 'delivered';
    case Completed = 'completed';
}
