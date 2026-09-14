<?php

namespace App\Enums;

/**
 * Deliberately lean (same convention as DrawStatus's own docblock): no
 * "pending" case, because a `winner_claims` row is only ever created at
 * the moment the winner actually submits one — "not yet claimed" is
 * represented by the absence of a row, not a stored placeholder status.
 * See docs/WINNER_CLAIM_P10.md "Claim lifecycle".
 *
 * No resubmission path exists in this phase: `Rejected` is a terminal
 * dead end (documented limitation, see the docs file's "P11 boundary").
 */
enum WinnerClaimStatus: string
{
    case Submitted = 'submitted';
    case VerificationPending = 'verification_pending';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Approved = 'approved';
}
