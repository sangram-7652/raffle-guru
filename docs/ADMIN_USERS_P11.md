# Admin users (P11.12)

Staff accounts (`admins`) are managed through `AdminResource`, with every
mutation going through `App\Services\Admin\AdminManagementService`.

## Who can do what

Decided only by `AdminPolicy`, using existing abilities. No ability was added.

| Action | Ability | SuperAdmin | Operations |
|---|---|---|---|
| See the roster and detail | `admin.view` | yes | no |
| Create an admin | `admin.manage` | yes | no |
| Edit name and email | `admin.manage` | yes | no |
| Change role | `admin.manage` (`updateRole`) | yes | no |
| Activate / deactivate | `admin.manage` | yes | no |
| Reset another admin's password | `admin.manage` (`resetPassword`) | yes | no |
| See admin audit history | `audit.view` | yes | no |

Filament hides controls the policy denies. Hiding is never the security
boundary: the service re-checks every call against the acting admin's
current state, under a lock.

## Invariants (enforced in the service, not the UI)

1. At least one **active** SuperAdmin always exists. Deactivating or
   demoting the last one is refused.
2. An admin cannot deactivate or demote **themselves**, even when other
   SuperAdmins exist.
3. Emails are unique, compared case-insensitively.
4. Only the fields each operation takes are written. Role, status and
   password can't be set through profile editing.
5. A deactivated admin is refused by the service, and by the panel on
   their next request. Their authorization is re-read under the lock, not
   taken from a stale object.

## Concurrency

Every admin mutation runs in one transaction that locks the whole
`admins` table in `id` order, then checks the invariant and writes the
change and its audit row together. The table is a staff roster (tens of
rows), so the cost is negligible. Revisit if it ever grows large.

Verified on real MySQL with forked racing processes
(`AdminManagementConcurrencyTest`). With the lock removed, the race tests
fail; with it, they pass consistently.

## Audit

`admin_audit_logs` is append-only (no `updated_at`, and the model refuses
update and delete). Events: `admin.created`, `admin.updated`,
`admin.role_changed`, `admin.activated`, `admin.deactivated`,
`admin.password_reset`, `admin.password_changed`. Each row
records the actor, the target, and safe metadata only: changed field
names, role from/to, and the operator's reason. Passwords, hashes, tokens
and session values are never recorded.

## Passwords

- At creation: set through the Admin model's `hashed` cast and
  `Password::defaults()`.
- Reset by a SuperAdmin: `AdminManagementService::resetPassword()` sets a
  temporary password, sets `must_change_password`, and bumps
  `session_version`. Needs `admin.manage` (`AdminPolicy::resetPassword`).
  Refused on your own account. The reason is required.
- The temporary password can be typed or generated in the form. It is
  never stored in plaintext, audited, logged, or shown after submission.
  Share it through a secure channel.
- Forced change: while `must_change_password` is set, the admin can sign
  in but can reach only `ChangeAdminPassword`. Completing the change
  requires the temporary password, rejects reusing it, and rate-limits
  attempts (5 per minute per admin). The admin is then signed out and
  signs in again.
- No email reset exists. The customer password broker is not used.

## Sessions

- Each admin has a `session_version`. Sign-in stamps the session with it.
  `EnforceAdminSession` compares the two on every admin request, including
  Livewire updates.
- The version is bumped on deactivation, password reset, and forced change.
  Any session stamped with an older version is signed out.
- Reactivation does not revive old sessions, because the version has moved.
- A session with no stamp is refused. Sign-in always writes one.
- Account state is also checked centrally on every request. An admin who
  can't access the panel is signed out with 403.
- A forced-change admin gets no abilities (`Admin::hasAbility()` returns
  false), so no policy can grant anything before the change is made.

## Sign-in and `last_login_at`

- `RecordAdminLogin` listens to Laravel's `Login` event for the `admin`
  guard. Filament fires it only after its own panel-access check, so an
  inactive admin never reaches it.
- A successful sign-in sets `last_login_at` and writes the session stamp.
  A failed attempt writes nothing.

## Reasons

Activation, deactivation, role change, and password reset all require a
reason of 5 to 500 characters. The reason is stored in the audit metadata.

## First SuperAdmin bootstrap (P11.14)

Every mutation above assumes an admin is already signed in to act as the
`$actor`. Before the very first one exists, there is nothing to sign in
as — so bootstrap needed one deliberate exception, not a second admin-
management implementation.

```
php artisan admin:create-super
```

- Run once, during initial deployment, directly on the server (or inside
  the `api` container) — never through the web.
- If no SuperAdmin exists yet, it asks for **name**, **email**, and
  **password** (entered twice, hidden input, never echoed), then creates
  an active SuperAdmin with the same validation rules `AdminResource`
  uses (`Password::defaults()`, a unique email).
- If a SuperAdmin already exists, it refuses immediately — before asking
  anything — and exits non-zero. It never modifies an existing admin, and
  it's safe to run as many times as you like after the first success: every
  run after the first is a no-op refusal.
- The one-time invariant it enforces is **"no SuperAdmin exists yet"**,
  not "no admin exists yet" — a roster that already has Operations staff
  but no SuperAdmin is still bootstrappable.

Implementation: a thin CLI layer
(`app/Console/Commands/CreateSuperAdmin.php`) over a dedicated
`AdminManagementService::bootstrapSuperAdmin()` — the one method on that
service that takes no acting admin, because none can exist yet. There is
no fake actor (no `admin_id = 1`, no system user): the new SuperAdmin is
recorded as both actor and target of its own `admin.created` audit row,
the same self-referential shape `completeForcedPasswordChange()` already
uses when an admin's only legitimate actor is themselves.

Concurrency: the method takes an unfiltered `lockForUpdate()` scan of the
whole `admins` table — the same locking statement shape every other
mutation here uses — inside one transaction with the "no SuperAdmin"
check and the insert. On MySQL/InnoDB this takes a lock on the gap after
the last row even when the table is completely empty, so two operators
running the command at the same moment still serialize: whichever
commits first wins, and the second sees the new SuperAdmin under its own
lock and refuses. Verified against real MySQL with forked racing
processes (`AdminBootstrapConcurrencyTest`, `#[Group('mysql')]`) — a
row-scoped lock (locking an admin that already has `role =
'super_admin'`) cannot provide this guarantee, because on the very first
bootstrap there is no such row yet to lock.

## Product decisions still open

1. **Self-management.** Self-deactivation and self-demotion are always
   refused. Relaxing that is a product decision.
2. **Pre-deploy sessions.** Admin sessions created before the session
   stamp existed are refused, so every admin signs in once after deploy.
   This is intended, but operators should expect it.
3. **Browser verification** is pending (see the phase report).
