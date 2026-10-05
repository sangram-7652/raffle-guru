<?php

namespace App\Listeners\Admin;

use App\Models\Admin;
use Illuminate\Auth\Events\Login;

/**
 * Runs on every successful admin login, through Laravel's own Login event
 * (Filament's Login page fires it via attemptWhen, after its panel-access
 * check, so an inactive admin never reaches this listener).
 *
 * - Stamps the session with the admin's current session_version, which
 *   EnforceAdminSession compares on every later request.
 * - Records last_login_at. Failed attempts never reach this listener.
 *
 * Writes through a query, not the model, so only these columns change.
 */
class RecordAdminLogin
{
    public function handle(Login $event): void
    {
        if ($event->guard !== 'admin' || ! $event->user instanceof Admin) {
            return;
        }

        $admin = $event->user;

        session()->put(Admin::SESSION_VERSION_KEY, (int) $admin->session_version);

        Admin::query()
            ->whereKey($admin->getKey())
            ->update(['last_login_at' => now()]);
    }
}
