<?php

namespace App\Enums;

/**
 * Deliberately independent of WinnerStatus — see
 * docs/WINNER_CLAIM_P10.md "Fulfilment": a winner's status only ever
 * shows delivery_pending / delivered / completed (customer-facing
 * lifecycle); this enum tracks the operational state of the physical
 * (manual, in this phase — no courier integration) fulfilment process
 * feeding into it.
 */
enum PrizeDeliveryStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Failed = 'failed';
}
