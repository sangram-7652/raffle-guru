<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;

class VerifyEmailController extends Controller
{
    /**
     * Reached directly by the browser when the user clicks the link in the
     * verification email — not an API/fetch call, so this responds with a
     * redirect to the frontend's status page rather than JSON.
     * EmailVerificationRequest (Laravel's own) validates the signature and
     * that the {id}/{hash} match the intended user before this runs.
     */
    public function __invoke(EmailVerificationRequest $request): RedirectResponse
    {
        $frontendUrl = rtrim(config('app.frontend_url'), '/');

        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->away("{$frontendUrl}/verify-email?status=already-verified");
        }

        $request->fulfill();

        return redirect()->away("{$frontendUrl}/verify-email?status=success");
    }
}
