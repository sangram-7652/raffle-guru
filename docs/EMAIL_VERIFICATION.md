# Email verification delivery

Investigated 2026-10-17: the local site said "We sent a verification
link to [email]... Click it to verify your account," but no email ever
reached a real inbox.

## Root cause

Not a bug in the verification logic — the notification pipeline works
correctly end to end (traced below). The gap is purely transport
configuration: `MAIL_MAILER=log` writes "sent" mail to
`storage/logs/laravel.log` instead of delivering it anywhere, and that
was the only mailer configured, in every environment, before this fix.

The frontend's "We sent a verification link to..." (`VerifyEmailStatus.tsx`)
is accurate about the backend having dispatched the notification, but
reads as a delivery promise to a user who has no reason to know the
mailer is a dev stub. This document is the fix for the transport; the
copy itself was left as-is (see "What wasn't changed" below).

## Traced flow (both notification-sending paths, confirmed by reading
## the actual code, not assumed)

- `RegisteredUserController::store()` → `event(new Registered($user))`
  → Laravel's built-in `SendEmailVerificationNotification` listener
  (wired in `AppServiceProvider::boot()`) → `$user->sendEmailVerificationNotification()`.
- `EmailVerificationNotificationController::store()` (the "Resend
  verification email" button) calls the exact same method directly,
  generating a **fresh** signed URL with a fresh expiry each time —
  confirmed: Laravel's default `VerifyEmail::verificationUrl()` always
  calls `URL::temporarySignedRoute(..., now()->addMinutes(config('auth.verification.expire')), ...)`
  on every call, not cached/reused.
- Both paths funnel into `MustVerifyEmail::sendEmailVerificationNotification()`,
  which calls `$this->notify(new \Illuminate\Auth\Notifications\VerifyEmail)`
  — **stock Laravel, zero customization** in this app (`App\Models\User`
  implements `MustVerifyEmail` but overrides nothing).
- `VerifyEmail extends Notification`, not `ShouldQueue` — confirmed by
  reading the vendor class. **It is never queued**; it's sent
  synchronously, in the same request, via whatever `config('mail.default')`
  resolves to. The `queue` container (`queue:work`) was a red herring
  for this specific symptom — it's healthy and correctly processing the
  three `ShouldQueue` jobs that do exist
  (`AllocateTicketsForOrder`/`CreateWinnerJob`/`DrawCompetitionJob`,
  none mail-related), but was never involved in verification email
  delivery either way.
- The signed-URL route itself (`verification.verify`, `routes/api.php`)
  and its signature/expiry are completely untouched by this fix — they
  were never the problem, and nothing about them changed.
- Confirmed in `storage/logs/laravel.log`: a correctly-formed email with
  a valid signed verification link was actually written there for a
  real registration — proving the notification/dispatch logic itself
  was always working; only the transport needed to change.

## What was actually broken: transport, not logic

Runtime env inside the `api` container, before this fix (redacted):
```
MAIL_MAILER=log
MAIL_HOST=127.0.0.1        (unused by the log driver)
MAIL_PORT=2525              (unused by the log driver)
MAIL_FROM_ADDRESS=hello@example.com
QUEUE_CONNECTION=redis      (irrelevant to this notification — see above)
```
`log` and `array` (the other stub) are both intentionally
non-delivering — they exist for tests and local work where no human
needs to actually receive the email. Neither was ever going to put
anything in an inbox.

## Fix: local development

Added a credential-free SMTP catch-all, `mailpit`
(`axllent/mailpit`), to `docker-compose.yml` (the **local-dev-only**
compose file — never referenced by `docker-compose.prod.yml`). The
`api` service's `environment:` block now overrides
`MAIL_MAILER=smtp`/`MAIL_HOST=mailpit`/`MAIL_PORT=1025` on top of
`apps/api/.env`'s own (unchanged, still-honest) `MAIL_MAILER=log`
default — the exact same "compose overrides `.env` for the container
network" pattern already used there for `DB_HOST`/`REDIS_HOST`.

- Web UI to actually see delivered mail: **http://localhost:8026**
  (not Mailpit's default 8025 — that collided with another project's
  own Mailpit container already running on this machine)
- No credentials of any kind — Mailpit accepts any or no SMTP auth,
  and never touches the real internet.
- `apps/api/.env`/`.env.example` were **not** changed to point at
  Mailpit — only the Docker Compose override was, so running the API
  outside this stack (`php artisan serve`) still honestly defaults to
  `log` rather than silently depending on a container that isn't
  running.

Bring it up:
```sh
docker compose up -d --build mailpit api
```

## Fix: production

**No credentials were invented, and none exist to verify today** —
`apps/api/.env`'s production counterpart has no real SMTP host
configured (`MAIL_HOST=127.0.0.1`, `MAIL_USERNAME=null`,
`MAIL_PASSWORD=null`). `config/mail.php`'s `smtp` mailer is stock
Laravel and already fully driven by env vars — **no code change is
needed** to go live once real credentials exist; only this set of
`.env` values on the production host:

```
MAIL_MAILER=smtp
MAIL_HOST=<your-provider-smtp-host>
MAIL_PORT=<your-provider-smtp-port>        # typically 587 (TLS) or 465 (SSL)
MAIL_USERNAME=<your-provider-smtp-username>
MAIL_PASSWORD=<your-provider-smtp-password>
MAIL_ENCRYPTION=tls                         # or "ssl", per the provider
MAIL_FROM_ADDRESS=<a-real-address-at-your-domain>
MAIL_FROM_NAME="${APP_NAME}"
```

Any standard transactional-email provider works unmodified (Laravel's
`smtp` mailer is transport-agnostic) — e.g. Postmark, Mailgun, Amazon
SES (SMTP interface), Resend, SendGrid. Pick one, get its SMTP
credentials from its own dashboard, and set the six values above in
the production `.env` — never commit them, never put them in
`docker-compose.prod.yml` directly (secrets belong in `.env` per this
repo's existing `CLAUDE.md` rule).

## What wasn't changed (deliberately)

- The `verified` middleware, signed-URL/expiry mechanism, and
  `VerifyEmailController` — all already correct, confirmed by reading
  them; touching them wasn't needed and would have been unrelated
  scope.
- `VerifyEmailStatus.tsx`'s copy — "We sent a verification link" is
  true of what the backend does (dispatches via the configured
  mailer); it doesn't claim real-world delivery succeeded, and
  rewording it is a product-copy decision, not a delivery bug, so it
  was left alone pending that decision.
- `email_verified_at` was never set directly as a substitute for real
  delivery for any account as part of this fix.
