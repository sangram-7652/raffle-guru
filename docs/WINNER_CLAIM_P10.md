# Winner, Claim & Prize Fulfilment Engine — P10

Builds the winner identification, claim, verification, and prize
fulfilment domain on top of P9's completed draw engine:

```
drawn (P9)
   │
   ▼
CreateWinnerJob resolves the winner from draw_results
   │
   ▼
pending_contact → contacted → verification_pending → verified
   → claim_approved → delivery_pending → delivered → completed
   │
   ▼
competition: drawn → completed
```

P10 **never** recalculates the winner — `draw_results` (P9) remains the
immutable source of truth. Every fact P10 stores is read from it, never
chosen or re-derived.

## 1. Architecture

Four new tables (`winners`, `winner_claims`, `prize_deliveries`,
`winner_audit_logs`), three new PHP enums (`WinnerStatus`,
`WinnerClaimStatus`, `PrizeDeliveryStatus`), and five services under
`App\Services\Winner`:

| Service | Owns |
|---|---|
| `WinnerService` | Creating the one `winners` row per competition, from `draw_results` only |
| `WinnerLifecycleService` | The only place `winners.status` is ever written |
| `WinnerClaimService` | The only place `winner_claims` rows are written; the one customer-initiated mutation |
| `PrizeResolutionService` | Resolving which `Prize` a winner is entitled to |
| `PrizeFulfilmentService` | `prize_deliveries` progression; the only place a `Winner` reaches `delivered`/`completed` and a `Competition` reaches `completed` |

Every one of these mirrors an equivalent P7/P8/P9 pattern exactly (row
lock → re-read status → validate matrix → write → audit, all inside one
transaction) rather than inventing a new concurrency philosophy.
`CompetitionLifecycleService` (P8) remains the **only** place
`competitions.status` is ever written — P10 extends its matrix with one
edge (`drawn → completed`) and one event dispatch
(`CompetitionDrawn`), exactly as instructed; no second lifecycle-writing
mechanism was created.

## 2. Draw → winner handoff

Mirrors the P8 → P9 handoff (`CompetitionDrawPending` →
`DrawCompetitionJob`) exactly:

```
DrawService::finalize() commits draw_results + drawn (one transaction)
   │
   ▼ DB::afterCommit()
CompetitionDrawn event  (fired from CompetitionLifecycleService::transition())
   │
   ▼ (plain, non-queued listener)
DispatchWinnerCreation → CreateWinnerJob::dispatch()
   │
   ▼ (queued job, tries=3)
WinnerService::createFromDrawResult()
   │
   ▼
winners row created
```

The winner **never** exists before the draw result commits: the event
only fires from inside `DB::afterCommit()`, on the specific call that
performs `draw_in_progress → drawn`, never speculatively.

`CreateWinnerJob` is idempotent — `WinnerService::createFromDrawResult()`
is the single entry point it (and `winners:reconcile`, see §11) both
call. Running it 0, 1, or many times for the same competition always
converges to at most one winner.

## 3. Winner validation (fail-safe, not fail-open)

Before creating a winner, `WinnerService::assertConsistent()` checks, in
order: competition exists and is `drawn`; a `draws` row exists and is
`completed`; a `draw_results` row exists and belongs to this
competition; its `ticket_id` belongs to this competition and has a
`user_id`; and — the one check that can genuinely fail in production,
not just defensively — the winning ticket's order is **still** `paid`
(re-checked live, using the exact P9 eligibility predicate
`v1:ticket.order.status=paid`, never assumed). A winning order refunded
strictly *after* the draw completed (possible: `PaymentService::
markRefunded()` never touches tickets/draw_results) fails winner
creation outright. This is a hard stop, not a re-draw — P10 must never
recalculate the winner, so an already-drawn competition whose winning
order was refunded post-draw needs an explicit administrative decision,
out of scope for this phase.

Any failure logs the inconsistency (`Log::error('winner.creation_
inconsistent', …)`) and throws `WinnerException` — no winner is ever
created from incomplete or inconsistent data.

## 4. Winner lifecycle

```
pending_contact → contacted → verification_pending → verified
  → claim_approved → delivery_pending → delivered → completed
```

Strictly forward, one step at a time — `WinnerLifecycleService::
MATRIX` is the only definition of legal transitions, checked inside a
`SELECT … FOR UPDATE` transaction that re-reads the current status
before applying anything (same as `CompetitionLifecycleService` and
`DrawService`). Backwards transitions, arbitrary jumps, and a second
write path all return `false` (a safe no-op) or are simply impossible —
there is no controller, request, job, or model that writes
`winners.status` directly.

`actorType` on every transition is `'system'` (an automatic consequence
of another event, e.g. a verified claim moving the winner to
`verified`) or `'operator'` (a future admin/domain-service call) —
**never** `'customer'`. No controller anywhere calls
`WinnerLifecycleService::transition()` with customer-supplied status
data.

## 5. Claim lifecycle

```
(no row) → submitted → verification_pending → verified → approved
                                                        └→ rejected
```

Deliberately the smallest state machine that fits: no `pending` case
(a `winner_claims` row is only ever created at the moment the winner
actually submits one — "not yet claimed" is the absence of a row, not a
stored placeholder, same convention `DrawStatus` documents for itself).
`Rejected` is a terminal dead end in this phase — no resubmission path
exists (see §19 "P11 boundary").

**Submission** (`WinnerClaimService::submit()`) is the **one**
customer-initiated write anywhere in this whole domain. It:
- is only reachable while `winner.status === Contacted` (submitting is
  what starts verification — it atomically also transitions the winner
  to `verification_pending` in the same call),
- accepts exactly one field from the request (`notes` — free text),
  never `winner_id`/`user_id`/`ticket_id`/`competition_id`/`draw_id`/
  `prize_id`/`status` (see `SubmitWinnerClaimRequest`),
- is protected by `winner_claims.winner_id` being UNIQUE at the
  database level, with the winner row locked for the whole
  check-then-create window (see §9).

**Review transitions** (`WinnerClaimService::transition()` — moving a
claim to `verification_pending`/`verified`/`approved`/`rejected`) are
server-only; no controller reaches them. Two review transitions also
drive the parallel `Winner` state machine: claim → `verified` moves the
winner `verification_pending → verified`; claim → `approved` moves the
winner `verified → claim_approved`. Everywhere else the two state
machines are deliberately independent.

## 6. Verification

P10 establishes the verification *boundary*, not an external identity
check — no KYC provider is integrated (explicitly out of scope). What
"verified" means here is the set of facts already guaranteed by
construction plus a server-side confirmation step:

- `authenticated user.id === winner.user_id` — enforced on **every**
  winner/claim endpoint before any service call (404 on mismatch, same
  convention as `EntryController`), never inferred from anything in the
  request body.
- `winner.ticket_id === draw_results.ticket_id` — true by construction;
  `WinnerService` only ever reads it from the draw result, never
  accepts an override.
- `winner.competition_id === draw's competition` — same.

The only thing left for "verification" to actually *decide* is an
operational judgement call (did we confirm this person's identity well
enough to hand over a prize) — that is exactly what
`WinnerClaimService::transition($claim, Verified, …)` is: a domain
service ready for a future admin/operator surface to call, never a
customer-reachable action (§14 in the security test suite; no route,
anywhere, lets a customer set `verified`).

## 7. Fulfilment

```
claim_approved → delivery_pending (WinnerLifecycleService, operator-only)
   │
   ▼
PrizeFulfilmentService::createDelivery()   — resolves the prize, creates ONE prize_deliveries row
   │
   ▼
pending → processing → shipped → delivered → failed (from pending/processing/shipped)
```

`PrizeDeliveryStatus` is deliberately independent of `WinnerStatus` (see
both enums' docblocks) — a winner's own status only ever shows
`delivery_pending`/`delivered`/`completed`; the delivery's own status
tracks the (manual, in this phase) operational fulfilment process
feeding into those three.

**No courier integration, no fake tracking numbers.** `delivery_method`
and `tracking_reference` are plain operator-entered strings; nothing
generates or validates them against a real courier API. If fulfilment
is manual, it is modelled honestly as manual — not disguised as an
automated integration that doesn't exist.

## 8. Prize resolution (documented schema limitation)

`prizes` is `hasMany` from `competitions` (P2) — nothing in P9's
`draw_results`, or anywhere else, records which specific prize a
single draw/winner corresponds to. `PrizeResolutionService::
resolveForCompetition()`:

- **exactly one `prizes` row** → resolves it automatically (the
  overwhelmingly common case for a raffle-style single-prize
  competition),
- **zero or more than one** → throws `PrizeResolutionException`,
  refusing to guess. `PrizeFulfilmentService::createDelivery()` logs a
  `delivery_prize_unresolved` audit event and creates nothing; the
  winner stays `delivery_pending`, safe to retry once an operator
  assigns the correct prize.

This is the documented "smallest safe solution" the phase brief calls
for — not a guess, and not a redesign of the P2 schema. A future P11
admin surface assigning a specific prize to an ambiguous competition's
delivery is the natural extension point (see §19).

## 9. Competition completion

**Preferred rule, exactly as specified**:
`winner delivered → winner completed → competition completed`.

`PrizeFulfilmentService::advance()`, on reaching `PrizeDeliveryStatus::
Delivered`, calls `completeWinner()`, which:
1. `WinnerLifecycleService::transition(Delivered)`,
2. `WinnerLifecycleService::transition(Completed)`,
3. only then `CompetitionLifecycleService::transition(Completed,
   'winner completed')`.

`CompetitionStatus::Drawn → Completed` is the **only** edge into
`Completed` in the whole matrix (`CompetitionLifecycleServiceTest::
test_only_drawn_can_reach_completed` asserts every other status stays
permanently unable to reach it). It is never taken automatically right
after the draw — `PrizeFulfilmentTest::
test_competition_completion_never_happens_immediately_after_draw`
and `WinnerHandoffTest` both assert a freshly-`drawn` competition with
no winner progression stays `drawn` indefinitely.

## 10. Authorization

| Actor | Can |
|---|---|
| Anyone (unauthenticated) | Nothing — every winner/claim route requires `auth:sanctum` |
| Authenticated non-winner | See their own empty state (`{ "data": null }`); 404 on any other user's winner/claim id |
| The winner | Read their own winner/claim; submit **one** claim |
| System/operator (domain services only, no HTTP route) | Every status transition: contacted, verified, claim approved, delivery progression, completion |

Ownership is checked in the controller (`winner.user_id === $request->
user()->id`, 404 on mismatch — never 403, matching `EntryController`'s
established convention) **before** any service call, so no service
method ever needs to re-derive "is this really the caller's winner".

## 11. Concurrency

Every write path follows the same shape P7/P8/P9 already established:
lock the relevant row `FOR UPDATE`, re-read state, validate, write, all
in one transaction — with a database UNIQUE constraint as the backstop
in case application logic somehow races past the lock. Verified with
real MySQL, `pcntl_fork()`-based tests
(`tests/Feature/Winner/WinnerConcurrencyTest.php`, same technique as
P7/P8/P9's own concurrency suites):

| # | Scenario | Guard | Tested |
|---|---|---|---|
| A | Two `CreateWinnerJob`-equivalent executions | Competition row lock + `winners.competition_id`/`draw_id`/`draw_result_id` UNIQUE | ✅ real fork |
| B | Two simultaneous claim submissions | Winner row lock + `winner_claims.winner_id` UNIQUE | ✅ real fork |
| C | Winner creation vs. reconciliation | Same `WinnerService::createFromDrawResult()` entry point, same lock | Covered by A + `WinnerReconcileCommandTest` |
| D | Two verification attempts | Winner row lock inside `WinnerLifecycleService::transition()` (identical mechanism to A/B/E) | Unit-level: `WinnerLifecycleServiceTest`/`WinnerClaimTest` |
| E | Two delivery creation attempts | Winner row lock + `prize_deliveries.winner_id` UNIQUE | ✅ real fork |
| F | Two completion attempts | Same `WinnerLifecycleService::transition()` lock as D | Unit-level: `WinnerLifecycleServiceTest` |

D and F share the *exact* mechanism proven under real concurrency in A/B/E
(there is only one transition-writing method in the whole domain), so a
fourth/fifth forked test would exercise the identical code path — the
unit-level matrix tests were judged sufficient rather than duplicating
the same proof five times.

## 12. Idempotency

- `CreateWinnerJob` / `winners:reconcile` → `WinnerService::
  createFromDrawResult()`: 0, 1, or many calls converge to one winner.
- `WinnerLifecycleService::transition()`: repeating an already-applied
  transition (or attempting an invalid one) is a safe no-op (`false`),
  never an exception, never a duplicate audit row.
- `PrizeFulfilmentService::createDelivery()`: a second call for a
  winner that already has a delivery returns the existing row.
- Queue retries at any of these points cannot create a duplicate
  winner, claim, or delivery — every write is guarded by both a row
  lock and a database UNIQUE constraint.

## 13. Reconciliation

`php artisan winners:reconcile` — crash-recovery safety net for the
handoff in §2 (a worker crash between the `drawn` commit and
`CreateWinnerJob` executing). Finds every competition `status = drawn`
with no `winners` row (`whereDoesntHave('winner')`), chunked
(`chunkById(100, …)` — never loads all drawn competitions into memory),
and calls the exact same `WinnerService::createFromDrawResult()` the
queued job calls. Never recomputes the draw result, never selects a new
ticket, never changes the winning user, never creates a duplicate.
Scheduled every 5 minutes (`routes/console.php`) as a safety net
alongside the primary event-driven trigger — same cadence and reasoning
as P9's `competition:draw` sweep.

## 14. API

All under `auth:sanctum`, prefixed `/api/v1/account`:

| Method | Path | |
|---|---|---|
| GET | `/account/winner` | The caller's single most recent winner, or `{ "data": null }` |
| GET | `/account/winners` | Paginated list of the caller's own winners |
| GET | `/account/winners/{winner}` | One winner (404 if not the caller's) |
| GET | `/account/winners/{winner}/claim` | The winner's claim, or `{ "data": null }` |
| POST | `/account/winners/{winner}/claim` | Submit a claim (`notes` only) |

`WinnerResource`/`WinnerClaimResource`/`PrizeDeliveryResource` are
explicit allow-lists — no raw foreign key (`user_id`, `ticket_id`,
`draw_id`, `prize_id`), no `metadata`, no delivery/admin `notes`, no
audit log is ever serialized (see §16).

## 15. Audit

`winner_audit_logs` — append-only (`UPDATED_AT = null`, same convention
as `draw_audit_logs`/`competition_lifecycle_events`), never exposed by
any API endpoint. Events written: `winner_created`,
`winner_status_changed` (from/to on every `WinnerLifecycleService`
transition), `claim_submitted`, `verification_started`,
`winner_verified`, `claim_approved`, `claim_rejected`,
`delivery_prize_unresolved`, `delivery_created`, `prize_shipped`,
`prize_delivered`, `winner_completed`. `actor_type` is `'system'`,
`'operator'`, or `'customer'` (the last only ever on `claim_submitted`
— the one customer-initiated event). No passwords, tokens, payment
credentials, or KYC secrets are ever logged.

## 16. Privacy

Never exposed to a customer, anywhere: another user's winner/claim,
email/phone/address, KYC data, delivery `notes` (operator-only field —
excluded from `PrizeDeliveryResource`), internal audit logs, `metadata`
on any of the four tables. Verified by
`WinnerApiSecurityTest::test_winner_resource_never_leaks_another_users_
data_or_internal_fields` and the cross-user access tests (§18).

No public winner-publishing endpoint was added — the phase brief is
explicit that this is out of scope unless already required, and it
isn't: `/winners` already exists as a separate, pre-existing static
prototype page (`apps/web/src/data/winners.ts`) unrelated to this real
backend domain, left untouched.

## 17. Notifications (integration point only)

No notification infrastructure exists anywhere in this codebase
(`app/Notifications` is empty) — per instruction, P10 does **not**
build one. The five natural notification points (winner identified,
claim reminder, claim approved, prize shipped, prize delivered) are
exactly the events already written to `winner_audit_logs` (§15) — a
future notification listener can subscribe to
`WinnerLifecycleService`/`WinnerClaimService`/`PrizeFulfilmentService`'s
existing audit writes (or the `CompetitionDrawn` event for "winner
identified") without any further domain change.

## 18. Frontend

`/account/winner` (customer-facing, `RequireAuth`-gated, same pattern as
the existing `/account/entries` page) — `GET /account/winner`, falling
back to `GET /account/winners/{id}/claim` once a winner exists. States
implemented: loading, empty (never won), error, winner identified
(claim available/submitted per server state), verification pending,
verified, claim approved, delivery pending/shipped/delivered,
completed. No control is ever rendered for a transition the server
would reject — e.g. no "mark verified" button exists anywhere in the
customer UI, because no such customer-reachable action exists on the
backend either.

## 19. P11 boundary

Deliberately left out of P10, as scoped:

- **Admin/operator UI** — none in P10. P11.9 added it (Filament
  `WinnerResource` / `WinnerClaimResource`), calling these services
  directly with no direct status writes: "Mark contacted" ->
  `WinnerLifecycleService::transition(..., actorType: 'operator',
  actorId: <admin>)`; claim review -> `WinnerClaimService::transition()`.
  P11.9's only domain change: `WinnerClaimService::transition()` gained a
  trailing `?int $actorId = null`, recorded on the claim's audit row and
  on the coupled winner transition's audit row (null keeps the previous
  behaviour), and `canTransition()` became public so the admin UI asks
  the service which moves are valid instead of copying its matrix.
  Delivery progression (`PrizeFulfilmentService`) is P11.10's.
- **Real KYC/identity verification** — `verified` is a server-confirmed
  boundary today (§6), not an external identity check.
- **Courier/tracking integration** — `delivery_method`/
  `tracking_reference` are operator-entered facts; no API call to any
  shipping provider exists.
- **Claim resubmission after rejection** — `WinnerClaimStatus::
  Rejected` is a terminal state in this phase; a future phase would
  need an explicit reopen path (and a reason to relax the `winner_id`
  UNIQUE constraint on `winner_claims`, or introduce a claim *history*
  table instead of a single current-claim row).
- **Ambiguous multi-prize competitions** — `PrizeResolutionService`
  refuses to guess (§8); a future admin surface assigning a specific
  prize is the natural extension.
- **Notifications** — see §17: audit events exist as the integration
  point, nothing is wired to actually send anything yet.
