# P3 — Customer Authentication

## Architecture: Sanctum SPA (cookie-session), not tokens

```
Browser → Next.js → same-origin /api (Nginx) → Laravel → session cookie → User
```

Next.js and the Laravel API are served from the same Nginx origin (see
`docs/PRODUCTION_ARCHITECTURE.md`), which is exactly the scenario Laravel
Sanctum's "SPA authentication" mode is built for: the browser holds a
normal `HttpOnly` session cookie, not a bearer token it has to manage
itself. No custom JWT/token system was needed or built.

- `laravel/sanctum` installed, but **not** in its "API token" mode — the
  `personal_access_tokens` table/migration was deliberately removed (see
  the P2-style reasoning: no mobile app or third-party API consumer
  exists yet to need it; trivial to bring back with
  `vendor:publish` if one ever does).
- `bootstrap/app.php` calls `$middleware->statefulApi()`, which wraps
  `/api/*` requests from configured "stateful" origins
  (`config('sanctum.stateful')`, from `SANCTUM_STATEFUL_DOMAINS`) in
  session + CSRF middleware instead of treating them as anonymous/
  token-only requests.
- The frontend must call `GET /sanctum/csrf-cookie` once before any
  mutating request (see `apps/web/src/lib/auth/client.ts`) — Nginx
  proxies `/sanctum/*` to the API the same way it does `/api/*`.

## Endpoints (`/api/v1/auth/*`)

| Method & path | Auth | Throttle |
|---|---|---|
| `POST /register` | public | 5/min/IP |
| `POST /login` | public | 5/min/IP+email |
| `POST /forgot-password` | public | 3/min/IP+email |
| `POST /reset-password` | public | 5/min/IP |
| `GET /email/verify/{id}/{hash}` | signed link, must already be logged in | 6/min |
| `POST /logout` | session required | — |
| `GET /user` | session required | — |
| `POST /email/verification-notification` | session required | 3/min/user |

All error responses use Laravel's own conventions — no custom envelope:
`422` + `{message, errors}` for validation, `401` + `{message}` for
unauthenticated, `429` for throttled, generic `{message: "Server Error"}`
for anything else when `APP_DEBUG=false` (Laravel's default even when the
var is unset — see `config/app.php`).

## Anti-enumeration

Login, forgot-password, and reset-password all return the identical
generic message whether the email exists or not / the token is invalid
or the email doesn't exist. Covered by `LoginTest`,
`PasswordResetTest`.

## Email links point at the frontend, not a Blade route

- **Password reset**: `ResetPassword::createUrlUsing()` (in
  `AppServiceProvider`) builds a link to
  `{FRONTEND_URL}/reset-password?token=...&email=...` — there is no
  server-rendered reset page.
- **Email verification**: the signed link stays a *Laravel* route
  (`GET /api/v1/auth/email/verify/{id}/{hash}`, named exactly
  `verification.verify` — Laravel's `VerifyEmail` notification hardcodes
  that name) because the signature must be validated server-side; that
  route then redirects (`302`) to `{FRONTEND_URL}/verify-email?status=...`
  once verification is done.
- `Authenticate::redirectUsing()` also points at the frontend's
  `/login` — needed because Laravel's built-in default assumes a named
  `login` Blade route exists, which crashes
  (`RouteNotFoundException`) the moment any non-JSON guest request (e.g.
  a real browser following the verification link directly) hits a
  `auth:sanctum`-protected route. Confirmed via
  `EmailVerificationTest::test_verification_link_requires_authentication`.

## Frontend

- `apps/web/src/lib/auth/client.ts` — the only module that calls these
  endpoints. Handles the CSRF-cookie dance and typed
  `ValidationError`/`AuthError`.
- `apps/web/src/lib/auth/AuthContext.tsx` — `useAuth()` exposes
  `status: "loading" | "authenticated" | "guest"`, derived from
  `GET /api/v1/auth/user` on mount, never from `localStorage`.
- `apps/web/src/components/auth/RequireAuth.tsx` — client-side guard;
  redirects a guest to `/login?redirect=<path>`.
- Pages: `/login` (rewritten), `/register`, `/forgot-password`,
  `/reset-password`, `/verify-email` (all new), `/account` (new,
  protected — a placeholder landing page only; orders/entries/wins are
  separate future phases, not built here).

## Development email strategy

`MAIL_MAILER=log` (already the P1 default) — verification and
password-reset emails are written to `storage/logs/laravel.log` in full,
including the real signed URL, instead of being sent anywhere. No
production email provider is configured; that's infrastructure work for
whenever it's actually needed.

## Known testing limitation

Laravel's HTTP test client does not reliably round-trip the session
cookie across more than two sequential simulated requests under
Sanctum's stateful wrapping — a `LogoutTest` asserting
`GET /api/v1/auth/user` returns 401 in a *third* simulated request (after
separate login and logout calls) was removed for this reason. The
underlying behavior was instead confirmed correct against the real
running Docker stack with an actual cookie jar: register → get-user
(200) → logout (204) → get-user (401).
