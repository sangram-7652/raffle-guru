<?php

namespace App\Filament\Support;

use App\Enums\OrderStatus;
use Illuminate\Support\Str;

/**
 * Presentation for OrderStatus badges (table, view page, payment view).
 * Colours match the P11.4 OrderOverviewWidget. Kept out of the P5 enum
 * so the domain enum stays UI-agnostic — same pattern as
 * CompetitionStatusBadge.
 */
final class OrderStatusBadge
{
    public static function label(?OrderStatus $status): ?string
    {
        return $status === null ? null : Str::headline($status->value);
    }

    public static function color(?OrderStatus $status): string
    {
        return match ($status) {
            OrderStatus::Paid, OrderStatus::Completed => 'success',
            OrderStatus::PaymentPending, OrderStatus::Processing => 'info',
            OrderStatus::Failed => 'danger',
            OrderStatus::Refunded => 'warning',
            OrderStatus::Pending, OrderStatus::Cancelled, null => 'gray',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(OrderStatus::cases())
            ->mapWithKeys(fn (OrderStatus $status): array => [$status->value => self::label($status)])
            ->all();
    }
}
