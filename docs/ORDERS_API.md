# Orders API (P5)

Cart and order management for RaffleGuru. Covers everything a customer
needs to build a basket and place an order **before payment** — payment
processing (P6) and ticket allocation (P7) are explicitly out of scope
here. See [`docs/SCHEMA_DESIGN_P5.md`](SCHEMA_DESIGN_P5.md) for the
underlying schema/design rationale.

## Authentication & ownership

Every endpoint below requires an authenticated session (Sanctum SPA
cookie auth — see [`docs/AUTHENTICATION.md`](AUTHENTICATION.md)). There
is no guest cart: an unauthenticated request to any of these routes gets
`401 Unauthenticated`.

All cart/order data is scoped to the authenticated user:

- A cart item has no `user_id` of its own — ownership is checked via its
  parent cart (`cart.user_id === $request->user()->id`).
- Orders are only ever looked up through `$request->user()->orders()`,
  never by a bare `id`/`reference` lookup against the whole table.
- A cross-user access attempt (guessed cart-item id or order reference)
  returns **404**, not 403 — this never confirms that the resource
  exists for someone else.

## Cart endpoints

All routes are under `/api/v1` and require `auth:sanctum`.

### `GET /v1/cart`

Returns the authenticated user's cart, creating it lazily on first
access (one cart per user — `carts.user_id` is a unique constraint).

```json
{
  "data": {
    "id": 1,
    "currency": "GBP",
    "items": [
      {
        "id": 5,
        "quantity": 3,
        "unit_price": 500,
        "currency": "GBP",
        "line_total": 1500,
        "competition": {
          "id": 10,
          "slug": "bmw-m4",
          "title": "BMW M4 Competition",
          "image": "https://.../bmw-m4-1.jpg",
          "draw_at": "2026-10-01T20:00:00+00:00"
        }
      }
    ],
    "subtotal": 1500,
    "count": 3
  }
}
```

### `POST /v1/cart/items`

Adds a competition to the cart, or increments the quantity of an
existing line for that competition (no duplicate lines per
competition — enforced by a `unique(['cart_id', 'competition_id'])`
constraint).

Body:

```json
{ "competition_id": 10, "quantity": 2 }
```

- `competition_id`: required, integer. Must resolve to a competition
  that is currently **purchasable** (`status = active` — see
  "Competition validation" below); otherwise `422` on `competition_id`.
- `quantity`: required, integer, `min:1`, `max:100`
  (`CartItem::MAX_QUANTITY` — a deliberately generous per-line cap
  against a single abusive request, not a business rule). The
  resulting line total (existing quantity + this request, capped at
  100) is also checked against the competition's
  `max_entries_per_user` when set, returning `422` on `quantity` if it
  would be exceeded.

Price is **always** resolved server-side from the competition's current
`entry_price`/`currency` — any price/currency/total sent by the client
is ignored entirely.

Returns the updated cart (same shape as `GET /v1/cart`).

### `PATCH /v1/cart/items/{item}`

Sets a line's quantity outright.

Body: `{ "quantity": 5 }` — same validation as above (`min:1`,
`max:100`, and `max_entries_per_user` if set). Returns the updated
cart. `404` if the item doesn't belong to the authenticated user's
cart.

### `DELETE /v1/cart/items/{item}`

Removes a line from the cart. Returns the updated cart. `404` if the
item doesn't belong to the authenticated user's cart.

There is no dedicated "clear cart" endpoint — the frontend clears a
cart by removing each line individually, since no bulk-clear route was
required.

## Order endpoints

### `POST /v1/orders`

Creates an order from the user's current cart. Steps, in order:

1. Load the user's cart (with items + competitions).
2. Reject with `422` on `cart` (`"Your basket is empty."`) if the cart
   has no items.
3. For every line, reload the competition fresh from the database and
   re-check it is currently purchasable. If any competition is no
   longer purchasable (sold out, closed, drawn, deleted, etc.), the
   **entire order is rejected** with `422` on `cart` naming the
   competition — no partial order is ever created, and the cart is left
   untouched so the customer can fix it and retry.
4. Re-resolve every unit price fresh from the competition (never from
   the cart item's snapshot, which may be stale, and never from
   anything the client sent) and recalculate `subtotal`/`total`
   server-side.
5. Inside a single database transaction: create the `Order`
   (`status = pending`), create one `OrderItem` per line with a full
   price/title snapshot, then delete the cart's items. Nothing is
   written unless every step succeeds.
6. Generate a unique public reference (`RG-XXXXXX`), enforced by a
   database unique constraint; on the rare collision, retry with a
   fresh reference (up to 3 attempts) rather than trusting
   application-level uniqueness alone.

Returns `201` with the created order (same shape as `GET
/v1/orders/{reference}` below). The order is always created with
`status: "pending"` — payment is never taken, marked as taken, or
implied to have been taken by this endpoint.

### `GET /v1/orders`

Paginated list of the authenticated user's own orders, newest first.

```json
{
  "data": [ { "id": 1, "reference": "RG-482915", "status": "pending", "...": "..." } ],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 10, "total": 1 }
}
```

### `GET /v1/orders/{reference}`

Returns a single order by its public reference, scoped to the
authenticated user. `404` if the reference doesn't exist, or belongs to
a different user — the response never distinguishes the two cases.

```json
{
  "data": {
    "id": 1,
    "reference": "RG-482915",
    "status": "pending",
    "currency": "GBP",
    "subtotal": 1500,
    "total": 1500,
    "items": [
      {
        "id": 1,
        "competition_id": 10,
        "competition_title": "BMW M4 Competition",
        "competition_slug": "bmw-m4",
        "quantity": 3,
        "unit_price": 500,
        "currency": "GBP",
        "line_total": 1500
      }
    ],
    "created_at": "2026-09-13T12:00:00+00:00"
  }
}
```

## Order lifecycle

`OrderStatus` is a PHP backed string enum (a plain `VARCHAR` column, not
a MySQL `ENUM` — adding a value later is a code change, not a
migration):

| Status | Set by P5? | Meaning |
|---|---|---|
| `pending` | **Yes — the only status P5 ever sets** | Order created, awaiting payment |
| `payment_pending` | No — P6 placeholder | Reserved for payment-in-progress states |
| `paid` | No — P6 placeholder | Payment captured |
| `processing` | No — P7 placeholder | Ticket allocation in progress |
| `completed` | No — P7 placeholder | Tickets allocated |
| `cancelled` | No — no cancel endpoint exists yet | Reserved |
| `failed` | No — P6 placeholder | Payment failed |
| `refunded` | No — P6/P7 placeholder | Reserved |

Every order created in P5 stays in `pending` forever (nothing in this
phase ever transitions it) — payment processing (P6) and ticket
allocation (P7) own every other state.

## Pricing rules

- Money is always an `unsignedInteger` of minor units (pence) — no
  floats anywhere in this domain.
- The client can never set a price, a currency, or a total. Every price
  is resolved from `Competition::entry_price`/`currency` at the moment
  of the request — once when adding to cart, and **again** when the
  order is created (since a cart line can go stale between those two
  moments).
- A competition is "purchasable" only when `status = active`
  (`Competition::scopePurchasable()`). This is narrower than P4's
  `scopePublic()` — a sold-out/closed/draw-pending/drawn/completed
  competition is still publicly visible but cannot be added to a cart
  or included in a new order.

## Payment boundary

P5 does not implement, and has no fields for: a payment gateway,
Razorpay/Stripe/any provider, payment webhooks, or payment
verification. An order is never marked `paid`, and the frontend never
displays anything implying payment succeeded — the order-created
response and the confirmation page both say "payment pending".
Connecting real payment is entirely deferred to P6.

## Ticket boundary

P5 does not implement, and has no fields for: ticket generation, ticket
numbers, ticket allocation, draws, or winners. `order_items` snapshot
what was purchased (competition, quantity, prize reference, price) but
never a ticket number — that engine is entirely deferred to P7.
