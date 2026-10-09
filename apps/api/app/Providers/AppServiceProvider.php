<?php

namespace App\Providers;

use App\Http\Middleware\Admin\EnforceAdminSession;
use App\Listeners\Admin\RecordAdminLogin;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // P12.5 — same shape as PaymentServiceProvider's
        // PAYMENT_GATEWAY=fake guard: fail at boot, unconditionally, not
        // only once something happens to read config('session.secure')
        // first. A production deployment without HTTPS in front of it is
        // already an unsupported configuration (see
        // docs/PRODUCTION_DEPLOYMENT.md §2 and
        // docs/GO_LIVE_CHECKLIST.md "J. HTTPS") — this just turns
        // "forgot to set SESSION_SECURE_COOKIE=true" from a silently
        // broken login (the cookie never gets sent) into a loud boot
        // failure instead.
        if ($this->app->environment('production') && ! config('session.secure')) {
            throw new \RuntimeException(
                'SESSION_SECURE_COOKIE must be true in production. See docs/PRODUCTION_DEPLOYMENT.md §2.',
            );
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Laravel 11+ dropped the EventServiceProvider skeleton file — this
        // is the standard replacement location for manual listener wiring
        // for a listener that isn't auto-discoverable (SendEmailVerification
        // Notification lives in Illuminate\Auth\Listeners, outside
        // app/Listeners, so Laravel's event auto-discovery never finds it).
        // App\Listeners\DispatchTicketAllocation (P7), by contrast, IS
        // auto-discovered from its handle(OrderPaid $event) type-hint —
        // registering it again here would double-dispatch the allocation
        // job. See docs/TICKET_ENGINE_DESIGN_P7.md "Order/payment handoff".
        Event::listen(Registered::class, SendEmailVerificationNotification::class);
        Event::listen(Login::class, RecordAdminLogin::class);

        // Livewire's update endpoint sits outside /admin, so the panel's
        // middleware never sees it. Every admin component action and render
        // goes through here, so the same session and forced-change checks
        // apply to it. `web` comes first so the session exists when the
        // check runs.
        Livewire::setUpdateRoute(fn ($handle, $path) => Route::post($path, $handle)
            ->middleware(['web', EnforceAdminSession::class])
            ->name('default-livewire.update'));

        // The frontend (apps/web), not a Laravel Blade view, renders the
        // reset-password screen — point the emailed link there instead of
        // Laravel's default "password.reset" route, which doesn't exist.
        ResetPassword::createUrlUsing(function ($user, string $token) {
            $frontendUrl = rtrim(config('app.frontend_url'), '/');

            return "{$frontendUrl}/reset-password?token={$token}&email=".urlencode($user->getEmailForPasswordReset());
        });

        // Laravel's own default (min 8 chars) — deliberately not layering on
        // mixed-case/symbol/number requirements nobody asked for.
        Password::defaults(fn () => Password::min(8));

        // The Authenticate middleware's built-in default assumes a named
        // "login" Blade route exists and throws RouteNotFoundException
        // when it doesn't — which every non-JSON guest request hits (e.g.
        // a real browser following the email-verification link directly,
        // not via fetch). Point it at the frontend's login page instead.
        // (JSON/api requests never reach this: expectsJson() short-circuits
        // to a plain 401 before redirectTo() is even called.)
        Authenticate::redirectUsing(fn () => rtrim(config('app.frontend_url'), '/').'/login');

        RateLimiter::for('login', fn ($request) => Limit::perMinute(5)->by($request->ip().'|'.strtolower((string) $request->input('email'))));
        RateLimiter::for('register', fn ($request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('forgot-password', fn ($request) => Limit::perMinute(3)->by($request->ip().'|'.strtolower((string) $request->input('email'))));
        RateLimiter::for('reset-password', fn ($request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('verification-notification', fn ($request) => Limit::perMinute(3)->by($request->user()?->id ?: $request->ip()));

        // P12.3 — the one unauthenticated, public POST endpoint in this
        // whole API (see docs/PAYMENTS_DESIGN_P6.md "Webhook flow");
        // authenticity comes entirely from the signature check inside
        // the controller, not from this limit. This exists only to
        // bound how much CPU/DB load an unauthenticated flood of
        // garbage (wrong-signature, or well-formed-but-duplicate)
        // requests can cost — never to throttle a genuine gateway,
        // which this project has no real delivery-volume data for yet
        // (documented assumption, see docs/LOAD_AND_CONCURRENCY.md
        // "Recommended production limits": 120/min/IP is generously
        // above any plausible real retry burst at this project's
        // current order volume, while still bounding an attacker).
        RateLimiter::for('payment-webhook', fn ($request) => Limit::perMinute(120)->by($request->ip()));
    }
}
