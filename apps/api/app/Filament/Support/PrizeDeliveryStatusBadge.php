<?php

namespace App\Filament\Support;

use App\Enums\PrizeDeliveryStatus;
use Illuminate\Support\Str;

/**
 * Presentation for PrizeDeliveryStatus badges — the P10 enum stays
 * UI-agnostic, same pattern as WinnerStatusBadge.
 */
final class PrizeDeliveryStatusBadge
{
    public static function label(?PrizeDeliveryStatus $status): ?string
    {
        return $status === null ? null : Str::headline($status->value);
    }

    public static function color(?PrizeDeliveryStatus $status): string
    {
        return match ($status) {
            PrizeDeliveryStatus::Pending, null => 'gray',
            PrizeDeliveryStatus::Processing => 'info',
            PrizeDeliveryStatus::Shipped => 'warning',
            PrizeDeliveryStatus::Delivered => 'success',
            PrizeDeliveryStatus::Failed => 'danger',
        };
    }
}
