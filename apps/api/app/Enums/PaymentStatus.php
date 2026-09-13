<?php

namespace App\Enums;

/**
 * Plain string column backed by a PHP enum — same convention as
 * CompetitionStatus/OrderStatus. Deliberately lean: see
 * docs/PAYMENTS_DESIGN_P6.md "Payment lifecycle" for why `processing`,
 * `cancelled`, and `partially_refunded` were considered and not added —
 * nothing in this phase's flow ever produces them.
 */
enum PaymentStatus: string
{
    case Created = 'created';
    case Paid = 'paid';
    case Failed = 'failed';
    case Refunded = 'refunded';
}
