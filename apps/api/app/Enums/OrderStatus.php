<?php

namespace App\Enums;

/**
 * Full future lifecycle — see docs/SCHEMA_DESIGN_P5.md. P5 only ever sets
 * Pending; everything else is a placeholder for P6 (payment) / P7 (ticket
 * allocation) so reaching them later is a code change, not a migration.
 */
enum OrderStatus: string
{
    case Pending = 'pending';
    case PaymentPending = 'payment_pending';
    case Paid = 'paid';
    case Processing = 'processing';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Failed = 'failed';
    case Refunded = 'refunded';
}
