# Payments API (P6)

Payment processing for P5 orders via Razorpay. See
[`docs/PAYMENTS_DESIGN_P6.md`](PAYMENTS_DESIGN_P6.md) for the full design
rationale (gateway abstraction, idempotency strategy, concurrency
handling) — this document is the endpoint/operational reference.

## Payment lifecycle

`PaymentStatus` (a plain string column backed by a PHP enum, not a MySQL
`ENUM`):

| Status | Meaning |
|---|---|
| `created` | Gateway order created, checkout not yet completed/verified |
| `paid` | Server-verified successful payment |
| `failed` | Signature/amount mismatch, or the gateway reported failure |
| `refunded` | The gateway reported a refund (see "Refund boundary" below) — audit-only, not initiated by this app |

An `Order` can have many `Payment` attempts (retries). `Order.status`
transitions to `paid` only alongside a `Payment` reaching `paid` — a
failed attempt never marks the order `cancelled` or `failed`; it stays
payable so the customer can retry.

## Authentication & ownership

`POST /v1/orders/{reference}/payment` and `POST /v1/payments/verify`
both require `auth:sanctum` (same cookie-session auth as every other P5
endpoint) and are scoped to the authenticated user — a cross-user
attempt (guessed order reference or gateway order id) returns **404**,
never confirming the resource exists for someone else.

`POST /v1/payments/webhook/razorpay` is the one exception: it requires
**no** customer session (a gateway server has none). Authenticity comes
entirely from the Razorpay webhook signature, verified before anything
else happens.

## Endpoints

### `POST /v1/orders/{reference}/payment`

Starts a new payment attempt for an existing, still-payable order.

1. Loads the order by reference, scoped to the caller.
2. `422` on `order` if it isn't currently payable (already
   paid/cancelled/completed/failed/refunded).
3. Creates a gateway order for **`Order.total`/`Order.currency`** —
   never anything from the request body (there is no request body on
   this endpoint).
4. Creates a new `Payment` row (`status: created`).

Response:

```json
{
  "data": {
    "key_id": "rzp_live_xxx",
    "gateway_order_id": "order_xxx",
    "amount": 1500,
    "currency": "GBP",
    "reference": "RG-482915",
    "name": "RaffleGuru"
  }
}
```

This is exactly what's needed to open Razorpay Checkout in the browser
— never `key_secret`, `webhook_secret`, or any internal id beyond the
public order reference.

### `POST /v1/payments/verify`

Called by the browser from Razorpay Checkout's success callback.

Body: `{ "razorpay_order_id": "...", "razorpay_payment_id": "...", "razorpay_signature": "..." }`

1. Resolves the `Payment` row by `(gateway, gateway_order_id)` — `404`
   if unknown or not owned by the caller.
2. Already `paid`? Returns the current order unchanged (`200`) — a
   duplicate/replayed verification call is a safe no-op.
3. Verifies the signature (proves the id pairing is authentic). Invalid
   → the payment is marked `failed`, `422` on `payment`.
4. Independently fetches the payment from the gateway and cross-checks
   its `order_id`/`amount`/`currency`/`status` against what this
   application stored — never against anything the client asserts.
   Mismatch → marked `failed`, `422` on `payment`.
5. Only once both checks pass: the payment and order are transitioned
   to `paid` together, inside a transaction with row locking (see
   `docs/PAYMENTS_DESIGN_P6.md` "Order/payment transition").

Response on success (`200`): the order, in the same shape as `GET
/v1/orders/{reference}` (see below) — now with `status: "paid"`.

### `POST /v1/payments/webhook/razorpay`

Gateway → server. No auth, no CSRF (see
`docs/PAYMENTS_DESIGN_P6.md`). Verifies `X-Razorpay-Signature` against
the **raw** request body before parsing or touching the database, then
handles a small set of event types:

- `payment.captured` → marks the matching payment/order `paid`.
- `payment.failed` → marks the matching payment `failed`, reverts the
  order from `payment_pending` back to `pending` if applicable.
- Anything containing `refund` → marks the matching payment/order
  `refunded` (see "Refund boundary").
- Anything else is acknowledged (`200`) and ignored.

Always responds `200` once the signature checks out, even if no
matching payment is found — see `docs/PAYMENTS_DESIGN_P6.md`.

### `GET /v1/orders/{reference}` / `GET /v1/orders` (P5, extended)

Both now include a `payment` field — the **latest** payment attempt
only, or `null` before any exists:

```json
{
  "data": {
    "...": "... (unchanged P5 fields)",
    "payment": {
      "id": 12,
      "gateway": "razorpay",
      "status": "paid",
      "amount": 1500,
      "currency": "GBP",
      "failure_message": null,
      "paid_at": "2026-09-13T12:05:00+00:00",
      "created_at": "2026-09-13T12:00:00+00:00"
    }
  }
}
```

## Razorpay integration

- Official `razorpay/razorpay` Composer SDK (`^2.9`).
- Credentials come only from environment variables:
  `RAZORPAY_KEY_ID`, `RAZORPAY_KEY_SECRET`, `RAZORPAY_WEBHOOK_SECRET`
  (see `apps/api/.env.example`). Never hardcoded, never logged, never
  returned by any endpoint.
- `PAYMENT_GATEWAY` (default `razorpay`) selects the bound
  `PaymentGatewayInterface` implementation — see
  `docs/PAYMENTS_DESIGN_P6.md` "Gateway abstraction".

### Verifying without real credentials

Set `PAYMENT_GATEWAY=fake` in `apps/api/.env` and restart the `api`
container to bind `FakePaymentGateway` instead — a deterministic,
network-free stand-in (see `docs/PAYMENTS_DESIGN_P6.md` "Test gateway"
for its exact conventions: `pay_fail_...`/`pay_mismatch_...` prefixes
simulate gateway failure/tamper responses). `FakePaymentGateway::sign()`
computes a valid signature for a chosen (order id, payment id) pair or
raw webhook body, so the whole initiate → verify → webhook flow can be
driven by hand (e.g. via `curl`) without a real Razorpay account or
without exercising Razorpay's own Checkout.js in a browser. Always set
`PAYMENT_GATEWAY` back to `razorpay` (or unset it) before deploying —
never `fake` in production. This is no longer just a convention to
remember: `App\Providers\PaymentServiceProvider` (P11.16) refuses to
boot at all — `APP_ENV=production` with `PAYMENT_GATEWAY=fake` throws
`PaymentGatewayConfigurationException` before any request is served.

## Idempotency & failure/retry behavior

- **Duplicate verification**: a repeated call with the same gateway ids
  is a no-op once the payment is already `paid` (checked before the
  signature is even re-verified).
- **Duplicate webhook delivery**: deduped by a `sha256` of the raw
  webhook body, enforced by a database unique constraint on
  `payment_webhooks (gateway, event_id)` — see
  `docs/PAYMENTS_DESIGN_P6.md` "Idempotency strategy".
- **Concurrent webhook + browser verification**: both paths funnel
  through the same row-locked transactional method
  (`PaymentService::markPaid()`); whichever commits first wins, the
  other becomes a no-op.
- **Failed payment**: the order stays payable (reverted to `pending` if
  it had moved to `payment_pending`); the customer retries by calling
  `POST .../payment` again, which creates a new `Payment` attempt
  against the **same** order — never a new order.

## Security

- `RAZORPAY_KEY_SECRET`/`RAZORPAY_WEBHOOK_SECRET` never appear in any
  API response or the frontend bundle.
- No card number, CVV, bank account, or UPI PIN is ever received or
  stored — Razorpay Checkout collects those directly; this backend only
  ever handles an order id, a payment id, and a signature.
- Every payment amount is server-resolved from `Order.total` and
  independently re-confirmed against the gateway's own record —
  never accepted from the client at any step.
- Ownership is enforced on every authenticated endpoint; cross-user
  access returns `404`.

## Refund boundary

No refund-initiation endpoint or admin UI exists. A gateway-reported
refund (typically initiated from the Razorpay dashboard, outside this
app) is passively recognized via its webhook event and reflected as
`Payment.status: refunded` / `Order.status: refunded`, purely so the
stored state doesn't silently go stale. See
`docs/PAYMENTS_DESIGN_P6.md` "Refund boundary".

## Ticket boundary

No ticket generation, numbers, allocation, draws, or winners exist in
this phase. `Order.status = paid` is the handoff point — P7 owns
everything from there.

## P7 handoff

P7 (ticket engine) should trigger off `Order.status` transitioning to
`paid` — no polling or additional payment-side hook is required. See
`docs/PAYMENTS_DESIGN_P6.md` "P7 handoff" and "Known limitation:
`latestPayment` under concurrent attempts" for the one nuance worth
reading before building on top of the `payment` field.
