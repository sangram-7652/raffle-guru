# Draw Engine — P9

Implements the final leg of the competition lifecycle P8 prepared:

```
draw_pending → draw_in_progress → drawn
```

P9 owns exactly these three states and the transition between them. It
does **not** own `drawn → completed` (P10), winner claims, prize
delivery, KYC, winner contact, winner publishing, referral rewards, or
affiliate commissions — all later phases.

## 1. Draw lifecycle

```
draw_pending
      │
      ▼
acquire competition lock
      │
      ▼
create draw attempt
      │
      ▼
draw_in_progress
      │
      ▼
create eligibility snapshot
      │
      ▼
CSPRNG selection
      │
      ▼
persist draw result
      │
      ▼
reacquire competition lock
      │
      ▼
drawn
      │
      ▼
audit committed
```

`CompetitionLifecycleService` (P8) remains the **only** place
`competitions.status` is ever written — P9 does not introduce a second
lifecycle-writing mechanism. Its transition matrix gained exactly two
new edges:

```
draw_pending      -> draw_in_progress
draw_in_progress  -> drawn
```

There is deliberately **no** edge back from `draw_in_progress` to
`draw_pending`. A crashed/stale attempt is recovered by starting a new
`draws` attempt row while the competition stays `draw_in_progress` (see
§8), never by moving the competition status backwards — consistent with
every other transition in the matrix being forward-only (P8 already
established and tested this for its own states).

There is also no edge to `completed` — that stays entirely out of reach
until P10 adds it.

## 2. Eligibility rules

Exact predicate, versioned as `v1:ticket.order.status=paid`
(`DrawEligibilityService::ELIGIBILITY_VERSION`):

> A ticket is eligible if and only if its `orders.status = 'paid'`.

Because `tickets.order_id` is already denormalized from the parent
`Entry` (P7), this is a direct `tickets ⋈ orders` join — no join through
`entries` is needed (an `Entry` carries no status of its own to check,
and is never edited or deleted after allocation).

This excludes:
- Tickets whose order is `pending`/`payment_pending` — cannot actually
  occur, since `TicketAllocationService` only ever allocates for an
  already-`paid` order.
- Tickets whose order is `cancelled`/`failed` — same: cannot occur,
  tickets are never created for these.
- **Tickets whose order is `refunded`** — the one real, reachable
  exclusion. `PaymentService::markRefunded()` (P6) can move an order
  from `paid` to `refunded` strictly *after* its tickets were already
  allocated (tickets/entries are immutable — a refund never deletes
  them, per P7's design). This is why eligibility must be computed live
  from `orders.status` at draw time, never assumed from the mere
  existence of a `tickets` row.
- Wrong-competition tickets — excluded by the ordinary
  `tickets.competition_id = ?` filter already present on every query.

## 3. Snapshot strategy

The requirement is auditability ("exactly which tickets were eligible
when this draw happened?") without ever copying a potentially
million-row ticket set into per-draw storage.

`draws` stores **snapshot metadata**, not a ticket-set manifest:

| Column | Purpose |
|---|---|
| `snapshot_at` | when the snapshot was taken |
| `eligible_ticket_count` | how many tickets were eligible |
| `eligibility_version` | which predicate definition was used |
| `snapshot_checksum` | `sha256(competition_id : count : min_ticket_number : max_ticket_number : sum_ticket_number)` |

The checksum is computed from a single SQL aggregate query
(`COUNT`/`MIN`/`MAX`/`SUM` over the eligible join) — MySQL streams the
aggregation internally; only one row (the aggregate result) ever reaches
PHP. `COUNT`+`MIN`+`MAX` alone can't detect a same-count substitution
(ticket A excluded, ticket B included, count unchanged); adding `SUM`
gives a cheap extra signal that the *specific set*, not just its size,
matches, without the cost of hashing every individual ticket id. This is
a deliberately practical middle ground — not cryptographically
tamper-proof against a determined attacker with direct DB write access
(nothing short of a full row-level hash chain would be), but sufficient
to catch any accidental snapshot/selection mismatch and to give an
auditor something concrete to recompute and compare later, at effectively
zero cost regardless of competition size.

**Reproducibility, not secrecy**: the eligibility predicate and the
checksum recipe are both fully documented (this file); anyone can
recompute `snapshot()` against the historical `orders`/`tickets` rows and
compare the count/checksum against what a given `draws` row recorded.
What *cannot* be recomputed after the fact is which random number was
drawn (a CSPRNG output is not derived from any of the stored data) — the
guarantee for the random choice itself instead comes from (a) the
selection algorithm being provably unbiased over the recorded eligible
count (see §6), (b) the winning ticket_number/ticket_id being written
once, atomically, alongside the snapshot metadata, in the same
transaction as the `drawn` status transition, and (c) `draw_results`
having zero application code path that ever updates it.

## 4. Randomness (RNG)

`random_int()` — PHP's CSPRNG — is the only randomness source anywhere
in the draw domain. No `rand()`, `mt_rand()`, `Math.random()`,
timestamps, hashes, ticket-id modulo arithmetic, or array-ordering
tricks. Verified by a dedicated test that grep-scans every file under
`app/Services/Draw` and `app/Jobs/DrawCompetitionJob.php` for the banned
function names (`tests/Feature/Draw/DrawRandomnessTest.php`).

## 5. Selection algorithm

Tickets are numbered sequentially per competition starting at 1 (P7),
but eligibility can exclude specific numbers (a refunded order's
tickets), so the eligible set is **not necessarily contiguous** — the
implementation must not assume it is.

**Primary path — rejection sampling by ticket_number**
(`DrawRandomnessService::selectEligibleTicket()`):

1. Draw a CSPRNG-uniform candidate `k = random_int(1, overallMaxTicketNumber)`.
2. Look up ticket `#k` for this competition with a single indexed point
   query (`tickets ⋈ orders`, filtered on the unique
   `(competition_id, ticket_number)` index) and check eligibility.
3. If eligible, that ticket wins. If not (refunded), draw again.

**Why this is unbiased**: rejection sampling from a uniform distribution
over a superset `[1, N]`, conditioned on membership in a subset `E ⊆
[1, N]`, is *exactly* uniform over `E` — not an approximation. Each
member of `E` is equally likely to be the first accepted draw, by the
same symmetry argument that makes "roll a die until it's not a 6, keep
the result" uniform over `{1..5}`.

**Why this scales**: each attempt costs one indexed lookup, independent
of competition size. With the expected (low) refund rate this converges
in O(1) attempts whether the competition has 10,000, 100,000, or
1,000,000 tickets — no `Ticket::all()`, no full-table scan, and never
more than one row of one ticket returned to PHP per attempt.

**Fallback path** (only if 500 rejection attempts are exhausted — a
pathologically high exclusion rate): pick a uniform index
`k = random_int(0, eligibleCount - 1)` and fetch the k-th eligible ticket
via `ORDER BY ticket_number OFFSET k LIMIT 1`
(`DrawEligibilityService::nthEligibleTicket()`). Still returns exactly
one row to PHP and is still exactly uniform over `E` (uniform index into
a fixed ordering of `E`), but costs `O(k)` inside MySQL to skip to the
offset — which is why it is the fallback, not the default.

Both the aggregate snapshot query and the point/offset selection queries
run inside **one transaction** (`AutomatedDrawStrategy::select()`),
relying on MySQL's default REPEATABLE READ isolation to give every read
in that transaction one consistent view — this is the opposite need
from P7/P8's `lockForUpdate()` lesson (which existed specifically to
*escape* a stale snapshot and see the latest committed data). Here the
snapshot *is* the point: we want the count and the later point-lookups
to agree with each other even if something else commits new tickets in
the meantime, and we deliberately do not lock the tickets/orders tables
to get that (would be far heavier than a read-only draw needs).

## 6. Concurrency strategy

The competition row is the single serialization point, exactly as in P7
(`TicketAllocationService`) and P8 (`CompetitionLifecycleService`):

- **Acquisition** (`DrawService::acquireOrRecover()`) locks the
  competition row, re-reads its current status under lock, and only
  then decides fresh-start vs. recovery vs. no-op — all inside one
  short transaction that releases the lock before the expensive
  snapshot/selection work runs.
- **Finalization** (`DrawService::finalize()`) reacquires the same
  lock, re-checks `status === draw_in_progress` AND no `draw_results`
  row already exists, and only then writes the result + transitions to
  `drawn` — all atomically in one transaction.
- **Database-level backstop**: `draw_results.competition_id` has a
  UNIQUE constraint. Even if application logic somehow raced past the
  lock checks above, the database itself physically cannot hold two
  result rows for one competition.

Verified with real MySQL, `pcntl_fork()`-based concurrency tests
(`tests/Feature/Draw/DrawConcurrencyTest.php`, same technique as P7's
`ConcurrencyTest`/P8's `LifecycleConcurrencyTest`):
- two workers attempting the same draw simultaneously → exactly one
  successful draw, exactly one `draw_results` row, complete audit trail.
- a draw execution racing lifecycle processing (P8) on the same
  competition → no illegal status transition, no duplicate draw.
- two finalization attempts racing each other → one final winner only.

## 7. Crash recovery

`draws` is an append-only log of **attempts**, not a single mutable
record — this is what makes recovery possible without ever touching
`competitions.status` backwards.

If a worker crashes after `draw_pending → draw_in_progress` but before
finalizing, the competition is left `draw_in_progress` with a `draws`
row stuck at `status = running`. A later call to
`DrawService::acquireOrRecover()` (from the queued job's natural retry,
the scheduled sweep, or an operator running `competition:draw
{competition}`) detects this:

- If the running attempt's `started_at` is within `STALE_LEASE_SECONDS`
  (900s), treat it as still potentially in-flight and no-op — don't
  steal work from a live worker.
- If it is older than the lease, mark it `failed` (with a reason and an
  audit row), then create a **new** attempt (`attempt = previous + 1`)
  and proceed — the competition never leaves `draw_in_progress`, and
  the old attempt's row and audit trail are preserved forever, never
  overwritten.

900 seconds is a generous bound: this phase's selection algorithm is
expected to complete in well under a second even for a 1,000,000-ticket
competition (§5), so anything still `running` after 15 minutes is
overwhelmingly more likely to be a dead worker than a slow one.
`heartbeat_at` was deliberately **not** added as a column — a periodic
mid-execution heartbeat only earns its complexity for operations
expected to run far longer than this one; `started_at` + the lease
timeout is sufficient here, and adding an unused column would violate
this codebase's "don't invent unnecessary fields" convention.

## 8. Idempotency

`DrawService::run()` is the single entry point both the queued job and
the artisan command call — there is exactly one code path that can ever
perform a draw. Every trigger converges through it:

- **Duplicate queue job** (retry, or `CompetitionDrawPending` firing
  twice defensively) → second call sees status no longer `draw_pending`
  (or a still-live `running` attempt) → no-op.
- **HTTP/scheduler retry** → same; there is no HTTP endpoint that can
  trigger a draw at all (see §12).
- **Worker restart** → covered by crash recovery (§7).
- **Operator reruns the command** → same `run()` path, same guards.

## 9. Database schema

```
draws
  id, competition_id, type, status, attempt,
  snapshot_at, eligible_ticket_count, eligibility_version, snapshot_checksum,
  randomness_method, started_at, completed_at, failed_at, failure_reason,
  timestamps
  index(competition_id, status, attempt)

draw_results
  id, draw_id [unique], competition_id [unique], ticket_id,
  ticket_number, eligible_ticket_count, selected_at, created_at

draw_audit_logs
  id, draw_id, competition_id, event, metadata (json), created_at
  index(draw_id, created_at), index(competition_id, created_at)
```

Deliberately **not** copied blindly from the phase brief's suggested
shape:
- `draw_audit_logs` is a genuinely separate table from P8's
  `competition_lifecycle_events`, not a reuse of it — that table's
  schema (`from_status`/`to_status`/`reason`) has no room for
  draw-specific context (ticket number, checksum, failure reason) and
  forcing it in would corrupt a schema P8 already tested and documented.
  `competitions.status` transitions into/out of the draw states
  (`draw_pending → draw_in_progress → drawn`) are still recorded in
  `competition_lifecycle_events` exactly like every other transition
  (via the same `CompetitionLifecycleService::transition()`) — P9 adds
  a second, complementary audit table for draw-internal detail, not a
  duplicate of the first.
- No `winners` table — that is explicitly P10's. `draw_results` records
  only competition/draw/ticket facts, never a user-facing "winner"
  concept (no claim status, no contact info, no publishing state).
- No `competitions.draw_type` column — see §14.

## 10. Audit model

Two complementary audit trails, deliberately not merged:

- **`competition_lifecycle_events`** (P8, reused unchanged): the
  competition-level fact "status went from X to Y, because Z" —
  `draw_pending → draw_in_progress` and `draw_in_progress → drawn` both
  appear here exactly like every P8 transition.
- **`draw_audit_logs`** (new): draw-execution-level detail —
  `draw.started`, `draw.stale_attempt_recovered`, `draw.completed`,
  `draw.failed`, each with structured, non-secret metadata (ticket
  number, checksum, attempt number, exception class/message — never a
  stack trace, never a credential).

Both are written only from `DrawService`/`CompetitionLifecycleService`;
neither is exposed by any public API endpoint.

## 11. Failure handling

`DrawService::run()`: if `AutomatedDrawStrategy::select()` throws
(currently only `NoEligibleTicketsException` — zero paid tickets left,
e.g. every order for a competition was refunded), the attempt is marked
`failed` with the reason recorded, a `draw.failed` audit row is written,
and the exception re-throws to the caller. The competition **stays**
`draw_in_progress` — it is never falsely marked `drawn`. A future retry
(once eligibility genuinely changes, which nothing in this phase does
automatically) would re-snapshot and could succeed; today this
surfaces as a permanently-failed queue job (`$this->fail()` in
`DrawCompetitionJob`, landing in `failed_jobs` for operator visibility)
plus a command that reports the failure without pretending otherwise.

Every retry preserves history: a new `draws` row is created rather than
reusing/overwriting the failed one, so the full attempt history — and
every audit row against it — survives indefinitely.

## 12. Public API behavior

`GET /api/v1/competitions` / `GET /api/v1/competitions/{slug}`
(`CompetitionResource`) gain exactly one new, conditional field:

```json
"draw": { "ticket_number": 4821, "drawn_at": "2026-09-14T20:00:03Z" }
```

— present only when `status === "drawn"` and a `draw_results` row
exists; absent (not `null`) otherwise. No draw id, no strategy/RNG
method, no snapshot checksum, no attempt/failure history, no winner
identity — all internal, or P10's ("winner publishing"). Publishing the
winning ticket number itself is treated as ordinary, expected raffle
transparency (the same information a physical raffle draw would
announce), not a claim/winner workflow.

`GET /api/v1/entries` / `GET /api/v1/entries/{entry}` gain
`competition.status` (read-only, reflects P8/P9 lifecycle) — nothing
about whether that entry's own tickets won.

No new route exists to trigger, retry, or view internal draw state.
There is no authenticated *or* unauthenticated HTTP path into
`DrawService` at all — only the CLI command and the queue worker can
reach it.

## 13. Security model

- **No draw-triggering route exists**, authenticated or not — see §12.
  `routes/api.php` gains zero new entries for P9.
- `competition:draw` is CLI-only (`Illuminate\Console\Command`); nothing
  in `routes/` or `app/Http` can invoke it.
- The winning ticket is never accepted as request input anywhere —
  `DrawResult` is populated exclusively by `DrawService::finalize()`
  from the strategy's own selection, never from any payload.
- Cross-competition selection is structurally impossible: every
  eligibility/selection query filters by the specific `competition_id`
  under lock; `DrawRandomnessService` and `DrawEligibilityService` never
  accept an unscoped ticket_number.
- Refunded/failed/cancelled/pending orders' tickets cannot win — see §2;
  verified by dedicated tests, not merely assumed.
- Duplicate draws are prevented at three independent layers: the
  competition row lock, the `draw_results.competition_id` unique
  constraint, and `DrawService`'s own re-check at finalization.
- No secrets, credentials, or stack traces are ever logged or stored in
  `draw_audit_logs`/`failure_reason` — only domain-level messages
  (e.g. "no eligible tickets", "stale attempt exceeded 900s lease").

## 14. Draw method/type — what was and wasn't added

`draws.type` (backed by `App\Enums\DrawType`, one case: `Automated`)
records **which strategy executed a given attempt** — this satisfies
the requirement for a "clearly defined draw method" at the one point it
is actually decided.

`competitions.draw_type` was **deliberately not added**. Nothing in this
phase (or any planned near-term phase) selects between strategies
per-competition — every competition uses `AutomatedDrawStrategy` today,
unconditionally. Adding a column with exactly one possible value creates
a field with no real configuration surface to justify it, contrary to
this codebase's established "don't invent unnecessary fields"
convention (see e.g. `OrderStatus`/`PaymentStatus`'s deliberately lean
enums). When a second strategy is real enough to need per-competition
selection, that is a one-column migration away — a code change, not
design debt, per the same convention those enums already set.

## 15. Future draw types

`App\Services\Draw\Strategies\DrawStrategy` is the extension point:

```php
interface DrawStrategy {
    public function type(): DrawType;
    public function select(Competition $competition, Draw $draw): DrawSelection;
}
```

`LiveDrawStrategy` (a human-hosted/video draw where the "random"
selection is really a confirmation of an externally-witnessed event) and
`InstantWinStrategy` (pre-determined winning positions revealed at
purchase time rather than one draw at the end) are **not implemented in
P9** — the phase brief explicitly excludes both. Adding either later
means: a new `DrawType` case, a new class implementing `DrawStrategy`,
and a binding decision in (an evolved) `DrawServiceProvider` — every
other piece (locking, attempt bookkeeping, crash recovery, audit,
finalization, the public API shape) is already strategy-agnostic in
`DrawService` and requires no change.

## 16. P10 boundary

P9 ends at `drawn` + an immutable `draw_results` row naming a
`ticket_id`/`ticket_number`. P10 is expected to:
- resolve that `ticket_id` to its owning `user_id` (via
  `Ticket::user()`, already denormalized from P7 — no new join needed)
  to identify the winner,
- introduce `drawn → completed`,
- build claim/KYC/prize-delivery/winner-publishing/notification
  workflows,

none of which P9 implements, scaffolds, or leaves partially built.

## Testing performed

- **Backend**: full `vendor/bin/phpunit` suite, including P0–P8
  regression, all passing (exact count in the final report).
- **Concurrency**: real MySQL, `pcntl_fork()`-based tests for
  simultaneous-draw, draw-vs-lifecycle-race, and finalization-race
  scenarios (`tests/Feature/Draw/DrawConcurrencyTest.php`), run
  repeatedly.
- **RNG**: source-scan for banned functions, uniformity/eligibility
  properties, a large-dataset test bounding query behavior (not
  millions of rows in CI — see that test file's own docblock for the
  scale actually used and why it's sufficient to catch a
  `Ticket::all()`-style regression).
- **Frontend/lint/build/Docker**: see the final P9 report.

## Performance notes (10k / 100k / 1,000,000+ tickets)

- **Snapshot**: one aggregate query (`COUNT`/`MIN`/`MAX`/`SUM`) plus one
  `MAX(ticket_number)` query. Both are index-covered
  (`unique(competition_id, ticket_number)`) and scale as a single
  sequential index scan — no PHP-side row iteration at any size.
- **Selection (primary path)**: O(1) expected indexed point lookups
  regardless of N, since the rejection rate depends only on the refund
  rate, not on competition size.
- **Selection (fallback path)**: O(k) inside MySQL for a single
  `OFFSET`/`LIMIT` query, bounded by `eligibleCount`; still returns one
  row to PHP. Only reached after 500 failed primary-path attempts,
  which requires an implausibly high refund rate to trigger even once.
- **Locking**: the competition row lock is held only for the brief
  acquire/recover and finalize steps (a handful of small writes each),
  never for the snapshot/selection work — so draw execution never
  blocks P8's lifecycle sweep or (already-closed-competition-only, per
  P8) ticket allocation for longer than those two short windows.
