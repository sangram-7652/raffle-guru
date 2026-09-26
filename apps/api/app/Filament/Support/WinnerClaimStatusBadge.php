<?php

namespace App\Filament\Support;

use App\Enums\WinnerClaimStatus;
use Illuminate\Support\Str;

/**
 * Presentation for WinnerClaimStatus badges — same pattern as the other status
 * badges; the P10 enum stays UI-agnostic.
 */
final class WinnerClaimStatusBadge
{
    public static function label(?WinnerClaimStatus $status): ?string
    {
        return $status === null ? null : Str::headline($status->value);
    }

    public static function color(?WinnerClaimStatus $status): string
    {
        return match ($status) {
            WinnerClaimStatus::Submitted, null => 'gray',
            WinnerClaimStatus::VerificationPending => 'info',
            WinnerClaimStatus::Verified => 'warning',
            WinnerClaimStatus::Approved => 'success',
            WinnerClaimStatus::Rejected => 'danger',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(WinnerClaimStatus::cases())
            ->mapWithKeys(fn (WinnerClaimStatus $status): array => [$status->value => self::label($status)])
            ->all();
    }
}
