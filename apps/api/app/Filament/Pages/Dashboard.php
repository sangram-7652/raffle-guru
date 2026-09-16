<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Cosmetic only (P11.4 §10) — title/subheading for the operations
 * console. Widget composition itself comes entirely from
 * app/Filament/Widgets via AdminPanelProvider's existing
 * ->discoverWidgets(), so this page adds no query, no data, no
 * authorization logic of its own.
 */
class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'RaffleGuru Operations';

    public function getSubheading(): string | Htmlable | null
    {
        return 'Operational overview';
    }
}
