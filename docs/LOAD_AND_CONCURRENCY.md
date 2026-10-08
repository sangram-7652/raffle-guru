# RaffleGuru — Load & Concurrency Hardening (P12.3)

Status: **audit + targeted gap-filling**, not a rewrite. The headline
finding of this phase is that P7-P11's concurrency design was already
correct and already tested at the row-lock level (see "Concurrency
audit" below) — the production-code changes this phase actually made
are narrow: one new rate limit, and the load-test tooling/documentation
itself. Everything else is new MySQL-concurrency test coverage and
evidence, not a code change, per this phase's own governing rule: *"If
the existing system is already concurrency-safe and no safe optimization
is justified, do NOT manufacture code changes just to create a P12.3
commit."*

## Concurrency audit

Every write path listed in this phase's brief was read end-to-end before
any code was touched. Findings, by area:

| Area | Mechanism | Verdict |
|---|---|---|
| Ticket allocation (`TicketAllocationService`) | Order row lock (idempotency boundary) + competition row lock, visited in ascending `id` order to avoid deadlocks, guarding a `lockForUpdate()` **count** (not a plain `SELECT COUNT`) for both capacity and per-user limit, `unique(competition_id, ticket_number)` as hard backstop | Correct. Already tested under real forked-process MySQL concurrency (`Tests\Feature\Ticket\ConcurrencyTest`). |
| Payment state (`PaymentService`) | Payment + Order row locks, idempotent status check before any write, `OrderPaid` fired only `afterCommit` and only on the transitioning call | Correct. **Gap found**: no real-concurrency test existed proving this — see "New tests" below. |
| Payment webhook idempotency (`PaymentWebhookController`) | Deterministic `sha256(raw body)` dedupe key, `unique(gateway, event_id)` as the atomic "claim this event" operation, `QueryException` 23000 mapped to idempotent 200 OK (not an error) | Correct. **Gap found**: no real-concurrency test existed — see "New tests" below. |
| Competition lifecycle (`CompetitionLifecycleService`) | Single owner of `competitions.status`; row lock + explicit from→to matrix; invalid/already-applied transitions return `false` (safe no-op), never an exception; domain events fired only `afterCommit` | Correct. Already tested (`Tests\Feature\Competition\LifecycleConcurrencyTest`), including a lifecycle-vs-ticket-allocation race. |
| Draw (`DrawService`) | Acquire lock (short — just the state transition) → release → do the expensive eligibility/selection work unlocked → reacquire lock to finalize, re-checking state and `draw_results` existence before writing; `draw_results.competition_id` unique as hard backstop; stale-lease recovery (15 min) for a crashed worker | Correct. Already tested (`Tests\Feature\Draw\DrawConcurrencyTest`), including a finalization race and a draw-vs-lifecycle race. |
| Winner creation (`WinnerService`) | Competition row lock for the whole check-then-create window; 10-point consistency re-check against live data (never recalculates/re-derives); `winners.competition_id`/`draw_id`/`draw_result_id` all unique as hard backstops | Correct. Already tested (`Tests\Feature\Winner\WinnerConcurrencyTest`). |
| Claim/fulfilment (`WinnerClaimService`, `PrizeFulfilmentService`) | Winner/claim/delivery row locks; `winner_claims.winner_id` and `prize_deliveries.winner_id` both unique; prize stock re-derived from live delivery rows under a locked prize row, never cached | Correct. Already tested (claim submission, delivery creation, `startFulfilment`, delivery progression — all in `WinnerConcurrencyTest`). |
| Admin/SuperAdmin invariants (`AdminManagementService`) | `lockAll()` — a full, ordered `lockForUpdate()` scan of the (small, low-volume) `admins` table — serializes every mutating admin operation against every other one; "at least one active SuperAdmin" re-checked against that locked snapshot | Correct. Already tested, including the empty-table bootstrap gap-locking hazard specifically (`AdminBootstrapConcurrencyTest`, `AdminManagementConcurrencyTest`). |
| Idempotency middleware | N/A — no generic HTTP idempotency-key middleware exists | **Not a gap.** Idempotency is handled per-domain (unique constraints + row locks, above), which is the right granularity here: the webhook has a real deterministic dedupe key (the raw body hash); order creation has no client-supplied operation id to key on in the first place (it's driven by cart state). A generic middleware would add a second idempotency mechanism without a concrete problem it solves. |
| Queue/job safety | `AllocateTicketsForOrder`/`DrawCompetitionJob`/`CreateWinnerJob` all: `$tries = 3` matching the worker's own flag; a deterministic domain exception (`CapacityExceededException`, `DrawException`, `WinnerException`) calls `$this->fail()` immediately — never retried as if transient; any other exception propagates to Laravel's normal retry/backoff | Correct, and already exactly matches this phase's own "never retry business-rule failures as if they were transient" rule. |
| Redis/cache | `CACHE_STORE`/`QUEUE_CONNECTION`/`SESSION_DRIVER` all `redis` (stock Laravel usage). The only direct `Redis::`/`Cache::` call in application code is the health check's `Redis::connection()->ping()` | No custom caching exists, so no stampede/invalidation risk exists either. Not introduced in this phase — see "Caching" below for why. |
| Database indexes | See "Database/index audit" below | No change — existing indexes already match the real query shapes; current data volume doesn't justify anything new. |
| Rate limiting | `login`/`register`/`forgot-password`/`reset-password`/`verification-notification` all throttled. Payment webhook, order creation, and public competition listing/detail were **not**. | **Gap found and fixed** — see "Rate limiting" below. |

Searched for every pattern the brief listed (`race`, `lockForUpdate`,
`sharedLock`, `DB::transaction`, `unique`, `duplicate`, `idempotency`,
`retry`, `deadlock`, `lock wait`, `timeout`, `concurrency`, `parallel`,
`isolation`, `queue`, `afterCommit`) across `app/` and `database/
migrations/` as part of this audit, in addition to reading every service
listed above directly.

## New tests (the actual gap this phase filled)

Two real-MySQL concurrency tests
(`tests/Feature/Payment/PaymentConcurrencyTest.php`, `#[Group('mysql')]`,
pcntl_fork() — same technique as every other concurrency test in this
codebase, for the same reason: SQLite's per-connection `:memory:`
database cannot exercise cross-connection row locking):

1. **`test_the_same_webhook_delivery_sent_twice_concurrently_is_claimed_exactly_once`**
   — two forked processes POST the byte-identical webhook body (same
   `sha256` dedupe key) to the real `/api/v1/payments/webhook/razorpay`
   route at the same time.
   - Workers: 2. Expected: both get `200`, exactly one `payment_webhooks`
     row claims the event, Payment/Order end up `Paid` exactly once.
   - **Actual: exactly as expected.** 2 tests, 10 assertions, 0 failures.
2. **`test_two_concurrent_mark_paid_calls_on_the_same_payment_apply_exactly_once`**
   — two forked processes call `PaymentService::markPaid()` directly on
   the same `Payment` (the layer below the webhook table — this is what
   actually protects a browser-verification call racing a webhook, or
   two webhooks that both resolve to the same Payment).
   - Workers: 2. Expected: neither call errors; Payment/Order end up
     `Paid` exactly once; `paid_at` is set once, not overwritten.
   - **Actual: exactly as expected.**

Both ran clean on the first try against real MySQL 8.4 — the existing
design (not new code) is what made that possible.

## Rate limiting

Added `throttle:payment-webhook` (120/min/IP —
`App\Providers\AppServiceProvider`) to the one unauthenticated public
POST endpoint in this API. Authenticity still comes entirely from the
signature check inside the controller, same as before — this limit
exists only to bound how much CPU/DB load an unauthenticated flood of
garbage requests can cost, independent of whether the signature is
valid. **120/min is a documented assumption, not evidence-based** — this
project has no real production webhook delivery-volume data yet (same
honesty as the rest of this document); it's chosen generously above any
plausible real retry burst while still bounding an attacker. Revisit
once real traffic data exists.

Deliberately did **not** add a limit to order creation
(`POST orders`/`POST orders/{reference}/payment`) or the public
competition listing/detail — both are already behind either
`auth:sanctum` (bounding abuse to having an account) or are the exact
pages this phase's own load test (below) proved handle real concurrent
read traffic cleanly with zero errors. Adding a limit with no evidence
of a problem would be exactly the "invented limit" this phase's brief
warned against.

Tested: `tests/Feature/Payment/WebhookRateLimitTest.php` (120 requests
succeed, the 121st within the same minute is throttled).

## Database/index audit

Ran `EXPLAIN` against the real local MySQL dev database for the three
highest-traffic query shapes:

- Competition listing, no search term (`WHERE deleted_at IS NULL ORDER
  BY draw_at, id`): `type: ALL` (full table scan), `rows: 8`.
- Competition listing, with a search term (`LIKE '%term%'` on
  `title`/`short_description`): also `type: ALL`.
- The ticket per-user-limit lock query
  (`WHERE competition_id=? AND user_id=? FOR UPDATE`): uses an index
  (`tickets_competition_id_ticket_number_unique`), not a full scan.

**This is not a missing-index problem.** At 8 rows, MySQL's optimizer
correctly prefers a full table scan over any index — a scan of 8 rows is
cheaper than an index lookup, and forcing index usage here would make
nothing faster. The existing migrations already have the composite
indexes that would matter at real volume: `(status, starts_at)`,
`(status, ends_at)`, `(status, draw_at)` on `competitions`;
`(competition_id, user_id)` on both `tickets` and `entries`;
`(competition_id, status, attempt)` on `draws`. A `LIKE '%term%'` search
can never use a B-tree index regardless of row count (the leading
wildcard rules it out) — a `FULLTEXT` index would be the real fix if
search ever becomes a measured bottleneck, but there is zero evidence
(no real traffic, no slow-query log entry) that it is one yet.
**No index was added in this phase** — per this phase's own rule, "only
add indexes when justified by actual query patterns," and the actual
query patterns here don't justify one.

## N+1 audit

Checked the highest-traffic read endpoints directly:

- `CompetitionController::index`/`show` — already eager-loads
  `['prizes', 'drawResult']`, with an explicit comment explaining
  exactly why (`drawResult` avoids one extra query per *drawn*
  competition on the page). No N+1.
- `OrderController::index`/`show` — already eager-loads `['items',
  'latestPayment', 'entries.competition', 'entries.tickets',
  'entries.order']`. No N+1.

No N+1 problems found on these paths. Admin-side listing/detail screens
were not re-audited in this phase (out of scope — P12.3's brief
prioritizes the public read-heavy paths and the write paths named in
"Load-test methodology" below; a separate admin-performance pass, if
ever needed, is future work, not folded into this one silently).

## Caching

Not introduced. There is no custom application-level caching today
(`Cache::`/`Redis::` outside the health check), so there is no stampede
or invalidation risk to manage — and this phase's own rule is explicit:
caching is justified only when the data is safe to cache, invalidation
is understood, and correctness is preserved, never merely to move a
benchmark number. Nothing audited here met that bar with real evidence
behind it.

## Deadlock/retry policy

No changes. The existing lock-ordering discipline (competitions locked
in ascending `id` order during multi-item ticket allocation; locks held
for the shortest possible window in `DrawService`) already avoids the
deadlock shapes this phase's brief calls out. No blind retry-all-
exceptions logic exists or was added — the three domain jobs
(`AllocateTicketsForOrder`, `DrawCompetitionJob`, `CreateWinnerJob`) each
`$this->fail()` immediately on a deterministic business-rule exception
and let Laravel's normal `--tries`/backoff handle only genuinely
transient failures. This was already correct; see "Queue/job safety" in
the audit table above.

## Load-test methodology

No load-testing tool existed in this repository before this phase. k6
(`load-tests/k6/read_paths.js`) was added — a single script file, no
custom framework, chosen because it needs no Node/JS toolchain install
(`grafana/k6` Docker image) and is purpose-built for exactly this.

**Production safety (hard guard, not a convention):** the script
inspects `BASE_URL`'s host *before issuing a single request* and throws
immediately unless the host is `localhost`/`127.0.0.1`, or
`ALLOW_NON_LOCAL_TARGET=STAGING_CONFIRMED` is explicitly set for a
genuinely isolated staging environment. There is no flag, typo, or
copied command that reaches an arbitrary host by accident — the default
behavior is to refuse, not to proceed.

**What was tested:** the public read-heavy paths named in this phase's
brief — `GET /api/v1/competitions` (listing) and `GET
/api/v1/competitions/{slug}` (detail) — plus `GET /api/v1/health` as a
lightweight control. 10 VUs each for listing/detail (sequential
30-second windows), 2 VUs for health.

**What was deliberately NOT load-tested in this session:** the write
paths (cart/order creation, the webhook callback, ticket allocation).
Correctness of those paths under concurrency is what
`PaymentConcurrencyTest`/`ConcurrencyTest`/etc. (real forked-process
MySQL tests, the right tool for proving correctness) already establish
above. Blasting synthetic write traffic at the *shared local dev
database* (also used for other work on this machine) to produce a
throughput number would risk polluting that data for no corresponding
benefit — a real write-path load test belongs in an explicitly isolated
staging environment with its own disposable database, which this
session did not have. This is a methodology gap this phase is
documenting honestly, not filling with an invented number.

### Running it yourself

```sh
docker run --rm --network host \
  -e BASE_URL=http://localhost:8080 \
  -e DETAIL_SLUG=<a real active competition slug> \
  -v "$PWD/load-tests/k6:/scripts" \
  grafana/k6 run /scripts/read_paths.js
```

## Baseline results (real, measured — not invented)

Captured against the local Docker Compose dev stack
(`docker-compose.yml`) on the development machine used for this phase,
**which was also concurrently running several other unrelated Docker
projects** — this is a sanity-check baseline on shared, noisy hardware,
not a clean, dedicated-hardware production benchmark. Two separate runs
showed real variance (avg latency 283ms vs. 353ms) consistent with that
noise; both are reported rather than cherry-picking the better one.

| Metric | Run 1 | Run 2 (explicit percentile stats) |
|---|---|---|
| Combined throughput | 14.46 iterations/s | 12.66 iterations/s |
| Error rate | 0.00% (0 / 1308) | 0.00% (0 / 1146) |
| p50 | — | 364ms |
| p90 | 439ms | 495ms |
| p95 | 475ms | 529ms |
| p99 | — | 637ms |
| max | 788ms | 760ms |

**Zero HTTP failures across both runs (2454 total requests).** The
honest takeaway is not a throughput number to optimize against — it's
that these two read paths handle real concurrent traffic, at this scale,
with no errors, no lock contention, and no degradation requiring a
code change. Whether ~350-500ms median/p90 latency is acceptable is a
product/infra decision (this is a PHP built-in dev server behind Nginx
in Docker, not the production `php-fpm` topology — see "Local
development vs. production" in
[`PRODUCTION_ARCHITECTURE.md`](PRODUCTION_ARCHITECTURE.md) — so these
absolute numbers don't transfer to production hardware/topology either).
Re-run this script against a real staging environment before drawing
any production-sizing conclusion from it.

## Recommended production limits

- `throttle:payment-webhook`: 120/min/IP (see "Rate limiting" above).
- Everything else already in place (`login`/`register`/
  `forgot-password`/`reset-password`/`verification-notification`) is
  unchanged by this phase.
- No new infrastructure-level limit (Nginx `limit_req`, etc.) is
  recommended from this phase's evidence — the read-path baseline above
  showed no need for one, and inventing one without evidence would
  violate this phase's own rule.

## Known bottlenecks

None identified with real evidence in this phase. The honest state: at
this project's current (near-zero) real traffic and data volume, there
is nothing to point at as an actual measured bottleneck — see "Database/
index audit" for why `EXPLAIN`'s full-table-scans here are expected
behavior, not a problem.

## Staging test procedure

Before trusting any load-test number for a production sizing decision:

1. Stand up an explicitly isolated staging environment — its own
   database, never the development or production one.
2. Seed it with a realistic data volume (real competition/ticket/order
   counts at the scale actually expected), not the ~8-row dev dataset
   this phase measured against.
3. Run `load-tests/k6/read_paths.js` with
   `ALLOW_NON_LOCAL_TARGET=STAGING_CONFIRMED` and `BASE_URL` pointed at
   that environment.
4. Extend coverage to the write paths this phase deliberately skipped
   (above) once a disposable database makes that safe.

## What must NOT be load-tested in production

Nothing in this repository's tooling can target production — the k6
script's safety guard (above) enforces this at the tool level, not just
as a written rule. Never remove or weaken that guard to "just run it
against prod real quick."
