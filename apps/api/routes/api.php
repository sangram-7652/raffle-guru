<?php

use App\Http\Controllers\Api\V1\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Api\V1\Auth\CurrentUserController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Api\V1\Auth\NewPasswordController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetLinkController;
use App\Http\Controllers\Api\V1\Auth\RegisteredUserController;
use App\Http\Controllers\Api\V1\Auth\VerifyEmailController;
use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\CompetitionController;
use App\Http\Controllers\Api\V1\EntryController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PaymentWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (v1)
|--------------------------------------------------------------------------
|
| Customer authentication (P3), public competition browsing (P4), cart &
| orders (P5), payment processing (P6), ticket entries (P7), plus
| infrastructure health checks (P1). Draw routes are a future phase.
|
*/

Route::prefix('v1')->group(function () {
    Route::get('health', [HealthController::class, 'index']);
    Route::get('health/status', [HealthController::class, 'status']);

    // Public — read-only. No authenticated/admin management endpoints
    // exist yet: there's no role/permission concept anywhere in the
    // architecture yet to gate them on (see docs/COMPETITIONS_API.md).
    Route::get('competitions', [CompetitionController::class, 'index']);
    Route::get('competitions/{slug}', [CompetitionController::class, 'show']);

    // Cart & orders — every route requires authentication (no guest cart,
    // see docs/SCHEMA_DESIGN_P5.md), and is scoped to the caller only.
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('cart', [CartController::class, 'show']);
        Route::post('cart/items', [CartController::class, 'store']);
        Route::patch('cart/items/{item}', [CartController::class, 'update']);
        Route::delete('cart/items/{item}', [CartController::class, 'destroy']);

        Route::get('orders', [OrderController::class, 'index']);
        Route::post('orders', [OrderController::class, 'store']);
        Route::get('orders/{reference}', [OrderController::class, 'show']);

        // Payment (P6) — see docs/PAYMENTS_API.md. Ownership is enforced
        // in the controllers (order/payment always scoped to the
        // authenticated user), same pattern as cart/orders above.
        Route::post('orders/{reference}/payment', [PaymentController::class, 'store']);
        Route::post('payments/verify', [PaymentController::class, 'verify']);

        // Entries/tickets (P7) — see docs/TICKET_ENGINE_API.md. Read-only:
        // no mutation endpoints exist, or should.
        Route::get('entries', [EntryController::class, 'index']);
        Route::get('entries/{entry}', [EntryController::class, 'show']);
    });

    // Gateway → server. Deliberately outside auth:sanctum — the gateway
    // has no customer session; authenticity comes entirely from the
    // webhook signature (verified inside the controller before anything
    // else happens). See docs/PAYMENTS_DESIGN_P6.md "Webhook flow".
    Route::post('payments/webhook/razorpay', [PaymentWebhookController::class, 'razorpay']);

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
