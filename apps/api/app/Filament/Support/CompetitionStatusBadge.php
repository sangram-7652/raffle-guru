<?php

namespace App\Filament\Support;

use App\Enums\CompetitionStatus;
use Illuminate\Support\Str;

/**
 * Presentation for CompetitionStatus badges, shared by the table and
 * view page. Kept here rather than as Filament HasLabel/HasColor
 * contracts on the domain enum, so the P2 enum stays UI-agnostic.
 */
final class CompetitionStatusBadge
{
    public static function label(?CompetitionStatus $status): ?string
    {
        return $status === null ? null : Str::headline($status->value);
    }

    public static function color(?CompetitionStatus $status): string
    {
        return match ($status) {
            CompetitionStatus::Draft, null => 'gray',
            CompetitionStatus::Scheduled => 'info',
            CompetitionStatus::Active => 'success',
            CompetitionStatus::SoldOut, CompetitionStatus::Closed => 'warning',
            CompetitionStatus::DrawPending, CompetitionStatus::DrawInProgress => 'primary',
            CompetitionStatus::Drawn, CompetitionStatus::Completed => 'gray',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(CompetitionStatus::cases())
            ->mapWithKeys(fn (CompetitionStatus $status): array => [$status->value => self::label($status)])
            ->all();
    }
}
