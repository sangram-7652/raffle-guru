<?php

namespace App\Filament\Support;

/**
 * Competition.entry_price / Prize.value / Prize.cash_alternative_value
 * are all `unsignedInteger` minor units (pence) — see their migrations.
 * Filament forms show admins pounds-and-pence, so every such field
 * needs the same load (pence -> "10.50") / save ("10.50" -> pence)
 * conversion. Centralized here instead of copy-pasted three times.
 *
 * toMinorUnits() never multiplies a float by 100 (10.1 * 100 is not
 * guaranteed to be exactly 1010.0) — it splits the decimal string into
 * whole/fraction digits and builds the integer from those. Input
 * reaching it has already passed RULES (non-negative, at most 2dp).
 */
final class MinorUnitMoney
{
    /**
     * Largest value an `unsignedInteger` pence column can hold, in pounds.
     */
    public const MAX_MAJOR_UNITS = 42_949_672.95;

    /**
     * Server-side validation for every money input — rejects 10.555
     * outright instead of silently rounding it.
     *
     * @var list<string>
     */
    public const RULES = ['decimal:0,2'];

    public static function toMajorUnits(?int $minorUnits): ?string
    {
        if ($minorUnits === null) {
            return null;
        }

        return intdiv($minorUnits, 100).'.'.str_pad((string) ($minorUnits % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function toMinorUnits(mixed $majorUnits): ?int
    {
        if ($majorUnits === null || $majorUnits === '') {
            return null;
        }

        // Livewire may hand over a JSON number (float) rather than the
        // typed string — render it back to a fixed 2dp string first.
        $string = is_float($majorUnits)
            ? number_format($majorUnits, 2, '.', '')
            : trim((string) $majorUnits);

        if (preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $string, $matches) !== 1) {
            throw new \InvalidArgumentException('Money value must be a non-negative amount with at most 2 decimal places.');
        }

        return (int) $matches[1] * 100 + (int) str_pad($matches[2] ?? '0', 2, '0');
    }
}
