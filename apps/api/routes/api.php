<?php

use App\Http\Controllers\Api\V1\Account\PromotionClaimController;
use App\Http\Controllers\Api\V1\Account\WinnerClaimController;
use App\Http\Controllers\Api\V1\Account\WinnerController;
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
use App\Http\Controllers\Api\V1\NewsletterSubscriptionController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PaymentWebhookController;
use App\Http\Controllers\Api\V1\PromotionController;
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

    // Footer email capture — public, unauthenticated, no session/ticket
    // side effects. See docs/EMAIL_CAPTURE.md for scope (subscription
    // only — no free-ticket grant).
    Route::post('newsletter/subscribe', [NewsletterSubscriptionController::class, 'store'])
        ->middleware('throttle:newsletter');
    Route::get('newsletter/unsubscribe/{token}', [NewsletterSubscriptionController::class, 'unsubscribe'])
        ->middleware('throttle:newsletter');

    // Public read of a promotion's current open/closed state — lets a
    // logged-out visitor see the offer is running before being asked
    // to log in. Claiming itself is authenticated, below. See
    // docs/EMAIL_CAPTURE.md "Option 1 proposal".
    Route::get('promotions/{slug}', [PromotionController::class, 'show']);

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

        // Winner/claim (P10) — see docs/WINNER_CLAIM_P10.md "API".
        // Read-only except the single claim-submission endpoint; every
        // route is scoped to the authenticated user inside its
        // controller (ownership check, never route-level middleware
        // alone — see WinnerController/WinnerClaimController). No
        // route anywhere lets a customer set winner status, verify
        // themselves, approve their own claim, or mutate delivery.
        Route::prefix('account')->group(function () {
            Route::get('winner', [WinnerController::class, 'current']);
            Route::get('winners', [WinnerController::class, 'index']);
            Route::get('winners/{winner}', [WinnerController::class, 'show']);
            Route::get('winners/{winner}/claim', [WinnerClaimController::class, 'show']);
            Route::post('winners/{winner}/claim', [WinnerClaimController::class, 'store']);
        });

        // Free-ticket promotion claim — requires a verified email, not
        // just an authenticated session (see docs/EMAIL_CAPTURE.md "Is
        // a verified, authenticated account mandatory to claim?"). The
        // authenticated user is always the claimant; nothing about
        // identity is ever read from the request body.
        Route::middleware('verified')->prefix('account')->group(function () {
            Route::get('promotions/{slug}/claim', [PromotionClaimController::class, 'show']);
            Route::post('promotions/{slug}/claim', [PromotionClaimController::class, 'store'])
                ->middleware('throttle:promotion-claim');
        });
    });

    // Gateway → server. Deliberately outside auth:sanctum — the gateway
    // has no customer session; authenticity comes entirely from the
    // webhook signature (verified inside the controller before anything
    // else happens). See docs/PAYMENTS_DESIGN_P6.md "Webhook flow".
    // throttle:payment-webhook (P12.3) bounds abuse of this one
    // unauthenticated public endpoint — see
    // docs/LOAD_AND_CONCURRENCY.md "Rate limiting".
    Route::post('payments/webhook/razorpay', [PaymentWebhookController::class, 'razorpay'])
        ->middleware('throttle:payment-webhook');

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

        // Reached directly by the browser from the email link (a plain
        // top-level navigation — from an email client, or pasted into the
        // address bar), so it needs the caller to already be logged in
        // (same browser that registered) — 'signed' validates the URL
        // hasn't been tampered with/expired.
        //
        // Deliberately 'web' + 'auth' here, NOT 'auth:sanctum'. Every
        // other route in this file relies on statefulApi()'s *conditional*
        // session bootstrap (bootstrap/app.php), which Sanctum's
        // EnsureFrontendRequestsAreStateful::fromFrontend() only enables
        // when the request's Referer/Origin matches SANCTUM_STATEFUL_
        // DOMAINS (see vendor/laravel/sanctum). A top-level navigation from
        // an email link/client (or a pasted URL) essentially never carries
        // a matching Referer/Origin — often none at all — so that
        // conditional session never started, the cookie was never read,
        // and 'auth:sanctum' reported "Unauthenticated" even for a
        // genuinely logged-in browser (confirmed empirically: the exact
        // same valid session cookie succeeds iff the Referer happens to
        // match, fails otherwise — the bug was never about whether the
        // user was logged in). The plain 'web' middleware group starts
        // the session unconditionally, exactly like a classic Laravel app
        // — correct for a route whose nature is "direct navigation",
        // never an XHR/fetch from the SPA. 'auth' resolves to the 'web'
        // guard (config('auth.defaults.guard')), the same guard every
        // login already authenticates against (AuthenticatedSessionController
        // uses Auth::attempt(), not a separate Sanctum guard) — so this
        // is not a new/different authentication mechanism, just the
        // right middleware to actually reach it for this one route.
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
