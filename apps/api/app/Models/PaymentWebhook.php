<?php

namespace App\Models;

use Database\Factories\PaymentWebhookFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per uniquely-identified gateway webhook delivery — see
 * docs/PAYMENTS_DESIGN_P6.md "Idempotency strategy". Deliberately not
 * related to Payment by foreign key: a webhook can reference a gateway
 * order id this application has no record of (stale/foreign data), and
 * that must still be safely recorded and acknowledged.
 */
#[Fillable(['gateway', 'event_id', 'event_type', 'metadata', 'processed_at'])]
class PaymentWebhook extends Model
{
    /** @use HasFactory<PaymentWebhookFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
