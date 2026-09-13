<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;

class PasswordResetLinkController extends Controller
{
    public function store(ForgotPasswordRequest $request): JsonResponse
    {
        // Always the same response regardless of whether the email exists —
        // Password::sendResetLink() itself silently no-ops for an unknown
        // email, so there's nothing else to branch on here.
        Password::sendResetLink($request->validated());

        return response()->json([
            'message' => 'If an account exists for that email, a password reset link has been sent.',
        ]);
    }
}
