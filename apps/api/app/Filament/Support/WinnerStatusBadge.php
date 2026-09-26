<?php

namespace App\Filament\Support;

use App\Enums\WinnerStatus;
use Illuminate\Support\Str;

/**
 * Presentation for WinnerStatus badges — same pattern as the other status
 * badges; the P10 enum stays UI-agnostic.
 */
final class WinnerStatusBadge
{
    public static function label(?WinnerStatus $status): ?string
    {
        return $status === null ? null : Str::headline($status->value);
    }

    public static function color(?WinnerStatus $status): string
    {
        return match ($status) {
            WinnerStatus::PendingContact, null => 'gray',
            WinnerStatus::Contacted, WinnerStatus::VerificationPending => 'info',
            WinnerStatus::Verified, WinnerStatus::ClaimApproved => 'warning',
            WinnerStatus::DeliveryPending, WinnerStatus::Delivered => 'primary',
            WinnerStatus::Completed => 'success',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(WinnerStatus::cases())
            ->mapWithKeys(fn (WinnerStatus $status): array => [$status->value => self::label($status)])
            ->all();
    }
}
