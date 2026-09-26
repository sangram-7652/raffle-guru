<?php

namespace App\Filament\Support;

use App\Enums\DrawStatus;
use Illuminate\Support\Str;

/**
 * Presentation for DrawStatus (one draw ATTEMPT's state) — same pattern
 * as the other status badges; the P9 enum stays UI-agnostic.
 */
final class DrawStatusBadge
{
    public static function label(?DrawStatus $status): ?string
    {
        return $status === null ? null : Str::headline($status->value);
    }

    public static function color(?DrawStatus $status): string
    {
        return match ($status) {
            DrawStatus::Running => 'info',
            DrawStatus::Completed => 'success',
            DrawStatus::Failed => 'danger',
            null => 'gray',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(DrawStatus::cases())
            ->mapWithKeys(fn (DrawStatus $status): array => [$status->value => self::label($status)])
            ->all();
    }
}
