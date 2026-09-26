<?php

namespace App\Filament\Support;

use App\Enums\PaymentStatus;
use Illuminate\Support\Str;

/**
 * Presentation for PaymentStatus badges. See OrderStatusBadge.
 */
final class PaymentStatusBadge
{
    public static function label(?PaymentStatus $status): ?string
    {
        return $status === null ? null : Str::headline($status->value);
    }

    public static function color(?PaymentStatus $status): string
    {
        return match ($status) {
            PaymentStatus::Paid => 'success',
            PaymentStatus::Failed => 'danger',
            PaymentStatus::Refunded => 'warning',
            PaymentStatus::Created, null => 'gray',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(PaymentStatus::cases())
            ->mapWithKeys(fn (PaymentStatus $status): array => [$status->value => self::label($status)])
            ->all();
    }
}
