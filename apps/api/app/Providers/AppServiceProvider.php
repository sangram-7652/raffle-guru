<?php

namespace App\Providers;

use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Laravel 11+ dropped the EventServiceProvider skeleton file — this
        // is the standard replacement location for manual listener wiring.
        Event::listen(Registered::class, SendEmailVerificationNotification::class);

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
    }
}
