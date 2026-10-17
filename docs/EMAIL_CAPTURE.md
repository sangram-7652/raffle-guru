# Footer email capture

A global newsletter-signup band rendered immediately above the site
footer on every page (`src/components/layout/NewsletterSignup.tsx`,
mounted in `src/app/layout.tsx`). Captures an email address for future
marketing contact. Deliberately small: no admin UI, no outbound-sending
infrastructure, no coupling to the Competition/Ticket/Order/Payment/Draw/
Winner domain.

## What's real

- `newsletter_subscribers` table: `email` (unique), `unsubscribe_token`
  (unique, opaque), `subscribed_at`, `unsubscribed_at`.
- `POST /api/v1/newsletter/subscribe` — public, unauthenticated,
  rate-limited (`throttle:newsletter`, 5/min/IP — see
  `RateLimiter::for('newsletter', ...)` in `AppServiceProvider`).
  Idempotent: a repeat submission of an already-subscribed address, or
  one re-subscribing after a prior unsubscribe, is a normal 200 — never
  a validation error, and the response never echoes the email back (see
  `NewsletterSubscriptionTest::test_response_never_exposes_the_submitted_email`).
- `GET /api/v1/newsletter/unsubscribe/{token}` — public, token-gated,
  one-click unsubscribe. Idempotent; an unknown token is a plain 404, not
  a 500.
- Frontend: `src/lib/newsletter/client.ts` (same CSRF-cookie handshake as
  every other stateful `/api/*` POST — see `src/lib/api/http.ts`),
  validated inline, loading/success/error states, submit button disabled
  while the request is in flight (duplicate-submission protection).

## What's explicitly NOT built: the "1 free ticket & entry" reward

The client's brief asks for "receive 1 free ticket and entry" on signup.
This is **not implemented**, by design, for a reason specific to this
codebase:

- Tickets/entries have exactly one creation path in the whole system:
  `TicketAllocationService`, triggered off `Order.status → paid` via the
  `OrderPaid` event (see `docs/TICKET_ENGINE_API.md` /
  `docs/TICKET_ENGINE_DESIGN_P7.md`). There is no second, order-independent
  way to create an `Entry`/`Ticket` anywhere in the codebase.
- Discount/promo codes and referral rewards were explicitly scoped out
  on purpose — `docs/SCHEMA_DESIGN_P5.md` ("No discount/promotion/tax
  logic exists — explicitly out of scope") and `docs/PRODUCTION_ARCHITECTURE.md`
  ("Promotion (discount codes) ... speculative module-map entries"
  removed). Building a comp-ticket grant here would be inventing exactly
  the business-rule machinery those docs deliberately removed — which
  competition would it enter? At what price basis, for per-competition
  capacity/limits enforced atomically at allocation time? Does it count
  against a user's per-competition entry limit? None of that is
  specified, and guessing would mean the "free ticket" either breaks
  capacity/limit invariants or silently does nothing.
- The CLAUDE.md rule for this repo is explicit: don't add
  Competition/Ticket/Order/Payment/Draw/Winner domain logic that wasn't
  asked for with the rules actually specified.

So the signup form keeps the client's offer copy ("Receive 1 Free Ticket
& Entry") as the advertised hook, but the success state only confirms
the subscription — it never claims a ticket was granted, and nothing
server-side ever creates one.

## Decision needed from the product owner

Pick one before the "free ticket" promise is real:

1. **Build it properly**: a `PromoTicketGrant`-style flow, specified with
   real rules — which competition(s) it applies to, how it interacts
   with per-competition/per-user ticket limits, whether it's one-per-email
   lifetime, and whether it needs its own row distinct from a paid
   `Entry`/`Ticket` (since those are 1:1 with a paid `OrderItem` today).
   This is genuine new domain logic, sized like a small phase of its own.
2. **Fulfil it operationally, outside the app**: ops/marketing manually
   grants something (e.g. a discount code, once that module exists) to
   subscribers out of band. No code change needed, but it doesn't scale
   and isn't idempotent/auditable the way the rest of this system is.
3. **Drop the "free ticket" framing**: change the on-site copy to a
   claim this system can actually back today (e.g. "never miss a
   competition" without the ticket promise) until (1) exists.

No PII is exposed in logs or public responses either way: the
subscribe/unsubscribe endpoints never return the submitted email, and no
outbound marketing email is actually sent yet (`MAIL_MAILER` stays at
its existing `log`/`array` default in every environment — adding a real
ESP integration is a separate, unrequested piece of scope and is left
out here).

## Option 1 proposal (2026-10-16) — approved 2026-10-17, see below

The product owner picked Option 1: build the reward properly. This
section is the proposed design, written up for approval **before** any
reward-awarding code is written, per the standing rule in this repo
("don't invent eligibility rules silently").

### Architecture findings that constrain the design

- `entries`/`tickets` rows are permanently FK-anchored
  (`restrictOnDelete`) to a real `user_id`, `order_id`, and (for entries)
  a unique `order_item_id` — see the P2/P7 migrations. There is no
  schema path to an Entry/Ticket that isn't owned by a real `User` and a
  real `Order`. **Conclusion: a promotional ticket must still be a real
  `Order` → `OrderItem` → `Entry` → `Ticket` chain**, not a parallel
  table that bypasses this.
- `OrderStatus::Paid` is read in exactly four places:
  `TicketAllocationService` (allocation gate), `DrawEligibilityService`
  (×3 — which tickets can win), `WinnerService` (winner resolution
  confirms the winning ticket's order is paid). A `Paid` order with
  `total = 0` and zero `Payment` rows satisfies all four honestly: no
  payment is fabricated (none was owed), and the ticket is genuinely
  draw-eligible — which it must be, or it isn't "a real ticket and
  entry" as the brief requires.
- `OrderResource`/Filament already null-handle `latestPayment` being
  absent (`OrderResource.php:32-33`), so a payment-less `Paid` order
  doesn't crash any existing read path.
- `OrderPaid` (the event that triggers async allocation) is fired only
  from `PaymentService::markPaid()`, specifically via `DB::afterCommit`.
  A promotional order never goes through `PaymentService`, so that event
  must **not** be relied on. The claim flow instead calls
  `TicketAllocationService::allocateOrder($order)` **synchronously**,
  inline in the claim request — needed anyway, since the brief requires
  confirming success only after the ticket/entry actually exist, not
  after an async job is merely queued.
- **Net result: zero changes to `TicketAllocationService` itself.**
  Because the promotional order already satisfies its one gate
  (`status === Paid`), the "smallest explicit extension" turns out to be
  calling the existing public method, not modifying it. Its own
  capacity/per-user-limit locking (`Competition` row lock,
  `max_entries`, `max_entries_per_user`) applies to a promotional
  allocation exactly as it does to a paid one — a promo claim still
  cannot push a competition over its real capacity.
- Promo/referral mechanisms were explicitly scoped out of the *paid*
  checkout path (`docs/SCHEMA_DESIGN_P5.md`) — this proposal doesn't
  reopen that; it's a separate, additive claim flow that happens to
  reuse the paid path's allocation primitive.
- Per-domain audit logs (`DrawAuditLog`, `WinnerAuditLog`,
  `CompetitionLifecycleEvent`) are each their own table, not one shared
  log — a `promotion_claims` table (see below) follows that same
  pattern and doubles as both the audit trail and the idempotency
  safety net.
- Admin authorization is a fixed `AdminAbility` enum +
  `AdminRole::abilities()` map (`app/Enums/AdminAbility.php`,
  `AdminRole.php`) — new abilities (`PromotionView`, `PromotionManage`)
  need to be added there, same as every other domain.

### Proposed schema

```
promotions
  id, name, slug (unique), competition_id (FK → competitions, restrictOnDelete)
  is_enabled (bool, default false — nothing is claimable until an admin turns it on)
  starts_at, ends_at (nullable datetime, UTC — same convention as every other timestamp)
  total_capacity (unsigned int, NOT NULL — no silent "unlimited")
  created_at, updated_at

promotion_claims
  id, promotion_id (FK, restrictOnDelete), user_id (FK, restrictOnDelete)
  newsletter_subscriber_id (nullable FK — set if/when that email also has a subscriber row)
  order_id (nullable FK, restrictOnDelete — set once the $0 order is created)
  status: 'pending' | 'fulfilled' | 'failed'
  failure_reason (nullable string — capacity/closed/disabled, never a stack trace)
  created_at, updated_at
  UNIQUE (promotion_id, user_id)   -- the hard safety net: one claim row per
                                    -- person per promotion, ever, race-proof
```

The `UNIQUE (promotion_id, user_id)` constraint is the same philosophy
as `tickets.unique(competition_id, ticket_number)` — the row lock
(`promotions` row, `lockForUpdate()`, same pattern as the existing
`Competition` lock in `TicketAllocationService`) is the primary
mechanism bounding `total_capacity` under concurrency; the unique
constraint is the hard backstop against a duplicate claim row no matter
what races past the lock.

### Proposed claim flow

1. `POST /api/v1/promotions/{slug}/claim` — **requires** `auth:sanctum`
   + Laravel's `verified` middleware (the user must be logged in with a
   verified email — the existing P3 flow, nothing new).
2. `PromotionClaimService::claim(User $user, Promotion $promotion)`,
   wrapped in `DB::transaction`:
   - Lock the `promotions` row; reject (no row written) if disabled,
     outside `starts_at`/`ends_at`, or `promotion_claims` count for it
     `>= total_capacity`.
   - Insert the `promotion_claims` row (`status = pending`) — the
     `UNIQUE (promotion_id, user_id)` constraint turns a second
     concurrent request from the same user into a clean, caught
     duplicate, not a second ticket.
   - Create `Order` (`status = Paid`, `total = 0`, `subtotal = 0`,
     `source = 'promotional'` — new column, see below) + one
     `OrderItem` for the promotion's competition, quantity 1.
   - Call `TicketAllocationService::allocateOrder($order)` inline. Its
     own `CapacityExceededException`/`PerUserLimitExceededException`
     (e.g. the competition itself is sold out, independent of the
     promotion's own capacity) is caught, the claim row is marked
     `failed` with a reason, and the whole transaction rolls back — no
     orphaned `Order`/`OrderItem` ever persists.
   - On success, update the claim row to `fulfilled`, store the
     resulting `entry_id`. Idempotently upsert the user's email into
     `newsletter_subscribers` (claiming implies subscribing — see "Does
     claiming require a prior subscription?" below).
3. A **new `orders.source` enum column** (`'purchase' | 'promotional'`,
   default `'purchase'`) is the single place "is this a paid or
   promotional order" is ever recorded. (Implementation note: NOT also
   denormalized onto `entries`/`tickets` as originally sketched here —
   doing so would mean `TicketAllocationService::allocateItem()` needs
   to accept and write a new field, contradicting "zero changes to
   TicketAllocationService." A report that needs to distinguish
   promotional tickets joins through `orders.source` instead — one
   extra join, never touching the allocation path.)
4. Response never echoes the email/internal IDs beyond what the
   customer already owns (their own `entry_id`/ticket count) — same
   privacy posture as the rest of `/account/*`.

### Admin (Filament)

- New `PromotionResource`: create/enable/disable a promotion, set
  `competition_id`/`total_capacity`/`starts_at`/`ends_at`. Shows live
  claimed/remaining capacity (`promotion_claims` count).
- New `PromotionClaimResource` (or a relation manager on
  `PromotionResource`): list claims with status/failure_reason, scoped
  to the fields already safe to show elsewhere in admin (`user.name`,
  `user.email` — already shown on `Customers`/`Orders` resources today,
  so no new PII exposure).
- New `AdminAbility::PromotionView` / `PromotionManage` cases, wired
  into `AdminRole::abilities()`.
- No destructive action exposed (can't delete a fulfilled claim or
  un-award a ticket from the admin panel — matches the "forward-only,
  never un-write a ticket" posture `TicketAllocationService` already
  has for paid orders).

## Decisions — approved (2026-10-17) and implemented

1. **Eligible competition**: an admin designates exactly one existing
   competition per promotion (`promotions.competition_id`), set up via
   the new `PromotionResource` in Filament. No competition is
   hardcoded; the feature does nothing until an admin creates a
   promotion row.
2. **Verified, authenticated account required**: yes, non-negotiable —
   `POST /v1/account/promotions/{slug}/claim` sits behind
   `auth:sanctum` + Laravel's `verified` middleware.
3. **Existing customers are eligible**: any verified account may claim
   once, new or existing — no "first order ever" exclusion.
4. **Claiming also subscribes**: a successful claim idempotently
   upserts the user's email into `newsletter_subscribers`
   (`NewsletterSubscriber::subscribe()`, the same helper the public
   subscribe endpoint uses) — one action, not two to coordinate.
5. **Admin permissions**: Super Admin can create/enable/disable/resize
   a promotion (`AdminAbility::PromotionManage`); Operations can view
   promotions and claims (`AdminAbility::PromotionView`) but not
   create or edit one.

## What was actually built

- **Backend**: `orders.source` column; `promotions` and
  `promotion_claims` tables; `Promotion`/`PromotionClaim` models
  (the latter immutable once `Fulfilled` — enforced at the model layer,
  not just by convention); `PromotionClaimService` (the sole writer —
  locks the `promotions` row, re-validates enabled/window/capacity/
  competition-purchasability under that lock, then calls the existing,
  **unmodified** `TicketAllocationService::allocateOrder()` inline
  inside its own nested transaction/savepoint, so a capacity failure
  there rolls back only the `Order`/`OrderItem`/`Entry`/`Ticket`
  attempt — never the already-committed `Failed` audit row); public
  `GET /v1/promotions/{slug}`; authenticated
  `GET`/`POST /v1/account/promotions/{slug}/claim`; a `PromotionResource`
  Filament admin page with a read-only claims relation manager;
  `AdminAbility::PromotionView`/`PromotionManage`.
- **Frontend**: `src/lib/promotions/client.ts` (`FOOTER_PROMOTION_SLUG`
  constant — an admin must create a promotion with this exact slug for
  the CTA to go live); `NewsletterSignup.tsx` now branches on
  auth/verification/promotion-open state: guest → plain subscribe form
  (unchanged real behavior) with a login/register nudge after
  subscribing when a promotion is open; unverified account → "verify
  your email" prompt; verified account → a real "Claim Your Free
  Entry" button that calls the claim endpoint and only ever shows
  success once the backend confirms a ticket was actually created.
- **Tests**: `tests/Feature/Promotion/PromotionClaimTest.php` (23
  cases — happy path, auth/verification gating, disabled/not-started/
  ended/exhausted promotion, competition no-longer-purchasable,
  competition-at-real-capacity leaving no orphaned rows, idempotent
  repeats, retry-after-failure reusing the same row, immutability
  guard, rate limiting, public/claim-status read endpoints),
  `tests/Feature/Promotion/ConcurrencyTest.php` (pcntl-forked, real
  MySQL — final-capacity race and same-user double-submit race, mirrors
  `tests/Feature/Ticket/ConcurrencyTest.php`'s proven pattern),
  `tests/Feature/Admin/PromotionResourceTest.php` (access, the
  Manage/View role split, a capacity validation case),
  `AdminAbilityMatrixTest` updated for the two new abilities. Frontend:
  `promotionsClient.test.ts`, `NewsletterSignup.test.tsx` (16 cases
  covering every branch above, including that a false "ticket claimed"
  state is never shown before the backend confirms one).

### Decisions requiring approval (see the question thread) before any
### reward-awarding code is written

1. **Which competition does the free ticket enter?** — proposed: an
   admin designates exactly one existing `Competition` per promotion via
   `promotions.competition_id` (no hardcoded competition, no customer
   choice at claim time).
2. **Is a verified, authenticated account mandatory to claim?** —
   proposed: yes, non-negotiable given the schema (see findings above).
3. **Are existing customers (people who've already bought tickets)
   eligible for the free ticket, or is it new-signups-only?**
4. **Does claiming require a *prior* `newsletter_subscribers` row, or
   does claiming itself also (idempotently) create/update one?** —
   proposed: the latter — claiming implies subscribing, so there's one
   coherent action instead of two separate ones to coordinate.
5. Promotion capacity, competition choice, and the start/end window are
   **admin-configured data, not hardcoded** — no specific numbers need
   approval here, only that the feature ships *disabled*
   (`is_enabled = false`) until an admin configures and turns it on.
