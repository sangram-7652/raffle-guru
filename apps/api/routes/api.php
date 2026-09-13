<?php

use App\Http\Controllers\Api\V1\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Api\V1\Auth\CurrentUserController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Api\V1\Auth\NewPasswordController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetLinkController;
use App\Http\Controllers\Api\V1\Auth\RegisteredUserController;
use App\Http\Controllers\Api\V1\Auth\VerifyEmailController;
use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (v1)
|--------------------------------------------------------------------------
|
| Customer authentication (P3) plus infrastructure health checks (P1).
| Domain routes (competitions, orders, tickets, payments, draws, ...) are
| added in their own phases.
|
*/

Route::prefix('v1')->group(function () {
    Route::get('health', [HealthController::class, 'index']);
    Route::get('health/status', [HealthController::class, 'status']);

    Route::prefix('auth')->group(function () {
        // -- Public --
        Route::post('register', [RegisteredUserController::class, 'store'])
            ->middleware('throttle:register');

        Route::post('login', [AuthenticatedSessionController::class, 'store'])
            ->middleware('throttle:login');

        Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
            ->middleware('throttle:forgot-password');

        Route::post('reset-password', [NewPasswordController::class, 'store'])
            ->middleware('throttle:reset-password');

        // Reached directly by the browser from the email link, so it needs
        // the caller to already be logged in (same browser that registered)
        // — 'signed' validates the URL hasn't been tampered with/expired.
        // Route name is exactly "verification.verify": Laravel's own
        // VerifyEmail notification hardcodes that name when building the
        // signed URL, so it can't be prefixed/changed.
        Route::get('email/verify/{id}/{hash}', VerifyEmailController::class)
            ->middleware(['auth:sanctum', 'signed', 'throttle:6,1'])
            ->name('verification.verify');

        // -- Protected --
        Route::middleware('auth:sanctum')->group(function () {
            Route::post('logout', [AuthenticatedSessionController::class, 'destroy']);
            Route::get('user', [CurrentUserController::class, 'show']);

            Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
                ->middleware('throttle:verification-notification');
        });
    });
});
