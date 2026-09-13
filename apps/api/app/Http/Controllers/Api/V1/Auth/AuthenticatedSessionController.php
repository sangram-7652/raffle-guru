<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthenticatedSessionController extends Controller
{
    public function store(LoginRequest $request): JsonResponse
    {
        // Deliberately generic on failure — same message whether the email
        // doesn't exist or the password is wrong, so a caller can't use this
        // endpoint to enumerate registered accounts.
        if (! Auth::attempt($request->validated(), false)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        // Prevents session fixation: a new session ID is issued now that the
        // authentication state has actually changed.
        $request->session()->regenerate();

        return (new UserResource(Auth::user()))->response();
    }

    public function destroy(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(null, 204);
    }
}
