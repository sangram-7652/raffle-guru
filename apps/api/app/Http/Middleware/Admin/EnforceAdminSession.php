<?php

namespace App\Http\Middleware\Admin;

use App\Filament\Pages\ChangeAdminPassword;
use App\Models\Admin;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The single place where an authenticated admin session is checked against
 * the admin's current state. It runs on every request to the admin panel,
 * before any page or Livewire component.
 *
 * 1. Session generation. The session must carry the session_version the
 *    admin currently has. Deactivation, password reset and password change
 *    bump it, so older sessions end here. A session with no stamp (for
 *    example one created before this check existed) is treated as stale.
 *
 * 2. Forced password change. While must_change_password is set, the only
 *    reachable pages are the password-change page, logout, and Livewire
 *    updates made by that page. Everything else redirects, or returns 403
 *    for Livewire. Admin::hasAbility() also returns false in that state, so
 *    a request that slipped past this check still gets no authorization.
 *
 * Guests and the login/logout routes pass through untouched.
 */
class EnforceAdminSession
{
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        if ($request->routeIs('filament.admin.auth.login', 'filament.admin.auth.logout')) {
            return $next($request);
        }

        $sessionAdmin = Auth::guard('admin')->user();

        if (! $sessionAdmin instanceof Admin) {
            return $next($request);
        }

        // Re-read the admin on every request rather than trusting the guard's
        // cached instance: a revocation must be seen even if the guard holds
        // an older copy. The refreshed row also becomes the guard's user, so
        // authorization later in this request sees the current state.
        $admin = Admin::query()->find($sessionAdmin->getKey());

        if ($admin === null) {
            Auth::guard('admin')->logout();
            $request->session()->invalidate();

            return $this->denied($request, route('filament.admin.auth.login'), Response::HTTP_UNAUTHORIZED, 'Your session has ended. Sign in again.');
        }

        Auth::guard('admin')->setUser($admin);

        // Account state is checked here, centrally, on every request, not left
        // to a later check that may not run for every route: an inactive admin
        // or one whose panel access has been withdrawn is signed out and refused.
        if (! $admin->canAccessPanel(Filament::getCurrentOrDefaultPanel())) {
            Auth::guard('admin')->logout();
            $request->session()->invalidate();

            return response('This account cannot access the admin panel.', Response::HTTP_FORBIDDEN);
        }

        if ((int) $request->session()->get(Admin::SESSION_VERSION_KEY) !== (int) $admin->session_version) {
            Auth::guard('admin')->logout();
            $request->session()->invalidate();

            return $this->denied($request, route('filament.admin.auth.login'), Response::HTTP_UNAUTHORIZED, 'Your session has ended. Sign in again.');
        }

        if ($admin->must_change_password && ! $this->isForcedChangeRequest($request)) {
            return $this->denied($request, route('filament.admin.pages.'.ChangeAdminPassword::getSlug()), Response::HTTP_FORBIDDEN, 'Password change required.');
        }

        return $next($request);
    }

    private function isForcedChangeRequest(Request $request): bool
    {
        if ($request->routeIs('filament.admin.pages.'.ChangeAdminPassword::getSlug())) {
            return true;
        }

        if ($request->routeIs('filament.admin.auth.logout')) {
            return true;
        }

        if (! $request->headers->has('X-Livewire')) {
            return false;
        }

        // A Livewire update is allowed only when every component it carries
        // is the password-change page itself. The component name is read from
        // the snapshot the browser sends back, and is the same in every update.
        $components = $request->input('components');

        if (! is_array($components) || $components === []) {
            return false;
        }

        foreach ($components as $component) {
            $snapshot = json_decode((string) ($component['snapshot'] ?? ''), true);

            if (($snapshot['memo']['name'] ?? null) !== ChangeAdminPassword::class) {
                return false;
            }
        }

        return true;
    }

    private function denied(Request $request, string $redirectTo, int $status, string $message): SymfonyResponse
    {
        if ($request->headers->has('X-Livewire')) {
            return response($message, $status);
        }

        return redirect()->to($redirectTo);
    }
}
