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
`admin.role_changed`, `admin.activated`, `admin.deactivated`. Each row
records the actor, the target, and safe metadata only: changed field
names, role from/to, and the operator's reason. Passwords, hashes, tokens
and session values are never recorded.

## Passwords

- Set at creation only, through the Admin model's `hashed` cast and
  `Password::defaults()` (minimum 8).
- Not changeable by anyone through this phase. There is no admin reset
  flow, so a forgotten password currently needs a database-level fix.
  See the product decisions below.

## Sessions

Deactivation takes effect on the next request, because panel access and
every ability re-check `is_active`. Existing session records are **not**
destroyed: admin sessions aren't tracked per account, so there's nothing
to revoke by admin ID.

## Product decisions still open

1. **Admin password reset.** Options: a SuperAdmin sets a temporary
   password plus a `must_change_password` flag (needs a migration), or an
   email reset (needs mail configured; `MAIL_MAILER=log` today).
2. **Session revocation.** Add a per-admin `session_version` checked on
   every admin request, or accept the current limitation.
3. **Self-management.** Currently self-deactivation and self-demotion are
   always refused. Relaxing that is a product decision.
4. **`last_login_at`.** The column exists but nothing writes it, so the
   list shows "Never recorded". Recording it means changing sign-in.
