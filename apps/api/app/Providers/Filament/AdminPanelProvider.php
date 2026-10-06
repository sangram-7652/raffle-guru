<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use App\Http\Middleware\Admin\EnforceAdminSession;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            // Staff-only identity boundary — never the customer `web`
            // guard. See config/auth.php and App\Models\Admin.
            ->authGuard('admin')
            ->login()
            // Without this, Filament's own authorization helper (see
            // Resources\Resource\Concerns\HasAuthorization) silently
            // ALLOWS an action when the specific policy method it needs
            // (restore, deleteAny, forceDelete, ...) doesn't exist —
            // the opposite of plain Laravel Gate::allows()'s deny-by-
            // default. Strict mode turns a missing policy method into a
            // LogicException instead, so a forgotten method fails loudly
            // in development/tests rather than silently granting access
            // in production (P11.5 security review finding).
            ->strictAuthorization()
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            // Every dashboard widget lives in app/Filament/Widgets and is
            // picked up here — no explicit ->widgets([...]) list. The
            // bundled AccountWidget/FilamentInfoWidget default widgets
            // (a profile card and a "powered by Filament" ad) are
            // deliberately dropped: P11.4 replaces the default dashboard
            // with a purpose-built operations console, not a generic
            // Filament starter page.
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                // Per-admin session generation and forced password change,
                // checked on every admin request (P11.12 hardening).
                EnforceAdminSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
