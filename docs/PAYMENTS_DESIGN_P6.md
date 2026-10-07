# P6 Payment Design — Cart/Order → Payment → Razorpay

Payment processing for the P5 Order domain. Scope is strictly: taking a
`pending` order to `paid` via a verified Razorpay payment. See the
bottom of this document for what is explicitly deferred to P7 (tickets)
and left as a documented limitation.

## Payment lifecycle

`PaymentStatus` (`app/Enums/PaymentStatus.php`) is a plain string column
backed by a PHP enum — same convention as `CompetitionStatus`/
`OrderStatus`:

```
created   — gateway order created, checkout not yet completed/verified
paid      — server-verified successful payment
failed    — signature/amount mismatch, or gateway reported failure
refunded  — gateway reported a refund via webhook (see "Refund boundary")
```

`processing`, `cancelled`, and `partially_refunded` were considered and
**deliberately not added**: nothing in this phase's flow ever produces
them. Razorpay Checkout + auto-capture (the default on a Razorpay
account, assumed here) resolves synchronously to captured/failed —
there is no genuine intermediate "processing" state to persist, and
there is no cancel-a-payment-attempt endpoint. Adding unused enum cases
would be exactly the kind of invented-but-dead state this phase was
told not to build.

## Order/Payment relationship: `Order hasMany Payment`

An order can have multiple payment attempts (retry after a failed or
abandoned checkout). `Payment` always belongs to exactly one `Order`;
`Order::payments()` is `hasMany`, and `Order::latestPayment()` is
`hasOne(...)->latestOfMany()` — the safe, indexed way to fetch "the
current attempt" without assuming there's only ever been one.

`Order::isPayable(): bool` — true only when `status` is `pending` or
`payment_pending`. This is the single gate every payment-initiation and
payment-success code path checks; a `paid`/`processing`/`completed`/
`cancelled`/`failed`/`refunded` order can never accept a new attempt or
be re-transitioned to `paid`.

A failed or abandoned payment attempt never touches the order's own
status beyond reverting `payment_pending` back to `pending` (see
"Failure handling") — the order itself is never marked `cancelled` or
`failed` by this phase. Order status and payment status are tracked
independently; the order's status is a *conclusion* ("is this order
paid"), the payment's status is a *log of one attempt* ("did this
specific attempt succeed").

## Gateway abstraction

`app/Services/Payments/`:

- `PaymentGatewayInterface` — `createOrder()`, `verifyPaymentSignature()`,
  `fetchPayment()`, `verifyWebhookSignature()`. No Razorpay-specific
  types leak past this boundary; `PaymentController`/`PaymentService`
  only ever depend on the interface.
- `RazorpayPaymentGateway` — wraps the official `razorpay/razorpay`
  Composer SDK. Constructed with `key_id`/`key_secret`/`webhook_secret`
  from `config('services.razorpay')` (i.e. `RAZORPAY_KEY_ID` /
  `RAZORPAY_KEY_SECRET` / `RAZORPAY_WEBHOOK_SECRET`) — never
  hardcoded, never logged.
- `FakePaymentGateway` — a fully self-contained, deterministic fake for
  automated tests (and for local/Docker verification when no real
  Razorpay credentials are configured — see "Test gateway" below).
- `PaymentService` — the only place that mutates a `Payment`/`Order`
  pair (`markPaid()`, `markFailed()`, `markRefunded()`). Both the
  browser-verification endpoint and the webhook handler call into this
  one service, so the "transition exactly once, under a lock" logic
  exists in exactly one place.

Bound in `App\Providers\PaymentServiceProvider` via
`config('services.payment_gateway')` (`RAZORPAY_KEY_ID` etc. otherwise
default to the real gateway):

```php
'fake'    => new FakePaymentGateway(),
default   => new RazorpayPaymentGateway($keyId, $keySecret, $webhookSecret),
```

P11.16: before any of the above, `PaymentServiceProvider::register()`
refuses to run at all when `app()->environment('production')` and
`config('services.payment_gateway') === 'fake'` — see
`docs/PAYMENTS_API.md` "Verifying without real credentials".

### Test gateway

`PAYMENT_GATEWAY=fake` (env var, defaults to `razorpay`) swaps the
binding for `FakePaymentGateway` app-wide. This is used by:

- PHPUnit — every payment test binds `FakePaymentGateway` directly via
  `$this->app->instance(...)`, not via the env var (keeps tests
  hermetic regardless of `.env`).
- Local/Docker manual verification when no real Razorpay account
  exists — see `docs/PAYMENTS_API.md` "Verifying without real
  credentials".

`FakePaymentGateway` never talks to the network. To remain usable
across **separate HTTP requests** (initiate → verify are two different
PHP processes with no shared memory), it deliberately uses the
project's own `payments` table as its simulated "gateway-side" record
once a `gateway_payment_id` is known — documented in the class itself.
Three deterministic conventions let a caller (test or curl) choose the
outcome up front by how they name the fake payment id:

- default `pay_...` → gateway reports the payment as `captured` with
  the amount/currency/order id this app itself stored when the payment
  row was created (the "happy path" — a real gateway would agree with
  what it was actually given).
- `pay_fail_...` → gateway reports `status: failed`.
- `pay_mismatch_...` → gateway reports a deliberately different amount,
  so the amount cross-check (see "Verification flow") can be exercised.

Signatures are a plain `hash_hmac('sha256', ..., $fakeSecret)` — the
exact same shape Razorpay itself uses, just with a constant instead of
an env secret, so the verification *code path* under test is identical
to production, only the key material differs.

## Gateway order creation / initiation flow

`POST /api/v1/orders/{reference}/payment` (authenticated, owner-only):

1. Load the order by reference, scoped to `$request->user()`.
2. `abort_unless($order->isPayable(), 422)` — no payment for an order
   that's already paid/cancelled/completed/failed/refunded.
3. Amount/currency come **only** from the persisted `$order->total` /
   `$order->currency` — never from the request body (there is no
   request body on this endpoint at all).
4. Create a new `Payment` row (`status: created`) and call
   `$gateway->createOrder($order->total, $order->currency, $order->reference)`.
   Store the returned gateway order id.
5. Return only what Razorpay Checkout needs in the browser: the public
   key id, gateway order id, amount, currency, and our own order
   reference. `key_secret`/`webhook_secret` never leave the server.

A new `Payment` row is created on every initiation call, even if an
earlier attempt for the same order is still `created` or is `failed` —
this keeps "one row per attempt" simple and matches `hasMany`; nothing
about a stale `created` row blocks a fresh one (Razorpay does not
require reusing gateway order ids for retries in this design).

## Verification flow

`POST /api/v1/payments/verify` (authenticated) — the browser calls this
from Razorpay Checkout's `handler` success callback, sending
`razorpay_order_id`, `razorpay_payment_id`, `razorpay_signature`.

1. `Payment::where('gateway', 'razorpay')->where('gateway_order_id', ...)->firstOrFail()`
   — 404 if unknown. Ownership: 404 if `$payment->order->user_id !== $request->user()->id`.
2. If already `paid`: return the current order (200) unchanged — a
   duplicate verification call is a safe no-op, not an error.
3. **Signature check** — `verifyPaymentSignature($orderId, $paymentId, $signature)`.
   This is a pure HMAC over the two ids using the account's key secret;
   it proves the id *pairing* is authentic (Razorpay actually issued
   this payment id for this order id), but says nothing about amount.
   Invalid signature → mark this `Payment` `failed`, respond `422`.
4. Persist `gateway_payment_id` on the payment row now that the
   pairing is cryptographically proven.
5. **Independent amount/currency check** — `fetchPayment($paymentId)`
   asks the gateway itself (never the browser) what it has on file for
   that payment id, and the result is compared against what *this
   application* stored on the `Payment` row when it created the
   gateway order (never against anything the client just sent).
   Mismatch on order id, amount, or currency → mark `failed`, `422`.
   Status other than Razorpay's `captured` → mark `failed`, `422`.
6. Only after both checks pass: `PaymentService::markPaid()` — inside
   `DB::transaction()`, re-fetches the `Payment` **and** `Order` rows
   with `lockForUpdate()`, re-checks `payment->status !== paid` and
   `order->isPayable()` (defends the race described in "Concurrency"),
   sets `payment.status = paid`, `payment.paid_at = now()`,
   `order.status = paid`. Returns the refreshed order (with its latest
   payment) as the response.

The frontend never decides "payment succeeded" itself — Razorpay
Checkout's in-browser success callback firing is only ever treated as
"go ask the server," never as the final answer.

## Webhook flow

`POST /api/v1/payments/webhook/razorpay` — **not** behind
`auth:sanctum`. Authenticity comes entirely from the webhook signature,
not a customer session (a real gateway server has no session cookie).
Because the request's `Referer`/`Origin` never matches
`SANCTUM_STATEFUL_DOMAINS`, Sanctum's `EnsureFrontendRequestsAreStateful`
middleware treats it as a plain, non-stateful API request — no CSRF
check is applied, and no session/auth is attempted. This was confirmed
against the actual middleware stack, not assumed.

1. Read the **raw** request body (`$request->getContent()` — untouched
   by `TrimStrings`/`ConvertEmptyStringsToNull`, which only operate on
   parsed input) and the `X-Razorpay-Signature` header.
2. `verifyWebhookSignature($rawBody, $signature)` first, before parsing
   or touching the database. Invalid signature → `400`, nothing
   recorded, nothing processed, no payload logged.
3. Compute an idempotency key (see "Idempotency strategy") and try to
   insert a `payment_webhooks` row. A unique-constraint violation means
   this exact delivery was already handled — respond `200` immediately
   without reprocessing.
4. Only on a first-seen event: dispatch on `event` type —
   `payment.captured` → `PaymentService::markPaid()` (same method the
   browser-verification path uses); `payment.failed` →
   `PaymentService::markFailed()`; anything containing `refund` (see
   "Refund boundary") → `PaymentService::markRefunded()`; anything else
   is acknowledged and ignored (Razorpay sends many event types this
   application has no reason to act on, and returning a non-2xx would
   just cause pointless retries).
5. Always respond `200` once the signature has checked out — even if no
   matching `Payment` row was found for the referenced gateway order id
   (e.g. stale/foreign test data). Retrying forever serves no purpose
   once we've genuinely looked and found nothing.

Only a curated, non-sensitive subset of the payload is stored in
`payment_webhooks.metadata` (event type, referenced gateway ids,
amount, currency, status, payment method) — never the full raw body,
and nothing is ever written to the application log at more than
"an event of type X arrived" granularity.

## Idempotency strategy

Razorpay does not guarantee a stable, documented top-level "webhook
delivery id" that this integration can rely on without guessing at
undocumented behavior. Rather than assume a header exists, the
idempotency key is **deterministic and self-contained**:

```
event_id = sha256(raw request body)
```

A retried delivery of the *same* event resends a byte-identical JSON
payload, so it hashes identically and is caught by the
`unique(['gateway', 'event_id'])` constraint on `payment_webhooks`. Two
genuinely different events will differ in at least their referenced
entity id, status, or timestamp, so they hash differently. The insert
itself is the atomic claim — exactly the same pattern P5 already uses
for `orders.reference` uniqueness (database constraint as the actual
guarantee, application code as a courtesy check), applied here as
"insert-or-detect-duplicate" instead of "insert-or-retry."

## Order/payment transition — atomicity

```
Payment: created ────▶ paid          (verify or webhook, success)
Payment: created ────▶ failed        (verify or webhook, failure)
Order:   pending/payment_pending ──▶ paid   (only alongside Payment → paid)
```

Both the verification endpoint and the webhook handler funnel through
`PaymentService::markPaid()`, which:

1. Opens `DB::transaction()`.
2. Re-selects the `Payment` row `lockForUpdate()`.
3. Re-selects the parent `Order` row `lockForUpdate()`.
4. If `$payment->status === Paid` already: commits with no changes
   (idempotent no-op) — this is what makes "webhook and browser
   verification arrive at the same instant" and "duplicate webhook"
   both safe: whichever request's transaction commits first wins the
   row lock, does the transition, and the other one finds
   `status === Paid` once it acquires the lock and does nothing.
5. Otherwise updates `Payment` and `Order` together and commits.

The unique `(gateway, event_id)` constraint on `payment_webhooks`
already prevents a *duplicate delivery* of the same webhook from
reaching this method a second time at all; the row-lock check above is
what additionally protects against a **different** payment attempt's
webhook and the browser's own verification call for that same attempt
landing concurrently.

## Failure handling

`PaymentService::markFailed()`:

- Sets `Payment.status = failed`, `failure_code`/`failure_message` from
  the gateway (or a generic message for a signature/amount mismatch,
  which is never attributable to a specific gateway error code).
- If `Order.status === payment_pending`, reverts it to `pending` —
  guarded by `lockForUpdate()` and re-checking the status is still
  `payment_pending` before writing (so it can never stomp a
  concurrently-succeeding attempt). If the order is already `paid` by
  the time this runs (a second attempt failing after a first attempt
  already succeeded), nothing changes.
- The order is **never** transitioned to `cancelled` or `failed` merely
  because one payment attempt failed — it stays payable, and the
  customer can retry (a fresh `POST .../payment` call, a fresh
  `Payment` row, no new `Order` row — the existing `pending` order is
  reused, never duplicated).

## Refund boundary

This phase does **not** build refund initiation — there is no endpoint
that calls Razorpay's refund API, and no admin UI. What it does build:
recognizing a refund the gateway itself reports (typically initiated
outside this app, e.g. from the Razorpay dashboard) via a webhook event
whose type contains `refund`, and reflecting it as `Payment.status =
refunded` / `Order.status = refunded` for audit accuracy — without that,
a dashboard-initiated refund would leave the order silently showing
`paid` forever, which is worse than a minimal, passive read of the
gateway's own event. Building a real refund-initiation flow (a
"Refund this order" action, partial refunds, refund reason tracking) is
explicitly deferred; see "P7 handoff."

## Security model

- `RAZORPAY_KEY_SECRET`/`RAZORPAY_WEBHOOK_SECRET` never appear in any
  API response, never reach the frontend bundle, and are read only from
  `env()`/`config()` inside `RazorpayPaymentGateway` — never logged.
- No card number, CVV, bank account, or UPI PIN is ever received,
  requested, or stored by this application — Razorpay Checkout collects
  those directly in an iframe hosted by Razorpay; this backend only
  ever sees an order id, a payment id, and a signature.
- Payment initiation and verification are both behind `auth:sanctum`
  and both re-check `$payment->order->user_id === $request->user()->id`
  (404, not 403, on mismatch — consistent with P5's cart/order
  ownership checks).
- The payment amount is never accepted from the client at any step —
  initiation reads `Order.total`; verification cross-checks the
  gateway's own independently-fetched amount against what this app
  stored, never against anything the browser asserts.
- The webhook endpoint requires no session but must pass signature
  verification before any parsing/DB work happens.

## Known limitation: `latestPayment` under concurrent attempts

`Order::latestPayment()` picks the most **recently created** payment row,
not necessarily the one that ultimately succeeded. In the product's
actual flow this never diverges — a retry only ever creates a new
attempt after the previous one has already failed or been dismissed, so
there is at most one "live" attempt at a time. It only shows a stale
result if something outside that flow creates a second concurrent
attempt for the same order before the first resolves (e.g. two browser
tabs, or a duplicate manual `POST .../payment` call) — the nested
`payment` field can then reflect the newer, still-`created` attempt
even after an older attempt has been verified and paid. `Order.status`
itself is unaffected and remains authoritative (verified directly
against the database during Docker verification — see the final
report); only the cosmetic "latest attempt" summary can lag in this
specific edge case. Not fixed here to avoid adding enum-ordering
logic for a case the real UI flow doesn't produce.

## P7 handoff

Ticket allocation is triggered by an order reaching `paid` — P7 owns
everything from there (allocating/reserving ticket numbers, moving the
order through `processing`/`completed`). This phase deliberately stops
at `Order.status = paid` and touches nothing about tickets, draws, or
winners. A full refund-initiation workflow (and any accompanying
admin/Filament UI) is also left to a later phase — see "Refund
boundary" above.
