# P5 Schema Design — Cart, Orders

## Cart: one row per user, no guest cart

`carts.user_id` is a **unique** FK — enforced at the database level, not
just in application code. No guest cart architecture: adding to cart
requires authentication (nothing else in this architecture has a guest
concept — P3 auth is the only identity system that exists). A cart is
created lazily on first access, not at registration.

No soft deletes on `carts`/`cart_items` — a cart is a working area, not a
financial record; it isn't referenced by anything that needs its history
preserved (an `Order` is a separate, independent snapshot, not an FK to
the cart it came from).

## Cart items: unique per (cart, competition)

`unique(['cart_id', 'competition_id'])` — adding the same competition
again increments quantity on the existing line rather than creating a
duplicate row, matching the existing (P1 mock) cart's behavior.

`competition_id` uses `restrictOnDelete()`, consistent with `prizes` in
P2 — a competition sitting in someone's cart shouldn't be hard-deletable
out from under them.

No `prize_id` on cart items: an entry is into a *competition*, not a
specific prize tier (today's 1:1 reality). Prize association is only
meaningful for the *order* snapshot (see below).

## Price integrity

Every price stored is server-resolved, never client-supplied:

- **Adding to cart**: load the competition fresh, verify it's
  `scopePurchasable()` (see below), snapshot `entry_price`/`currency`
  onto the cart item.
- **Creating an order**: re-resolve price fresh from the competition
  *again* at order-creation time (not the cart item's snapshot, which
  could be stale if pricing changed between add-to-cart and checkout) —
  this is the last point before the price is locked into an immutable
  order-item snapshot.

All money is `unsignedInteger` minor units (pence), matching P2/P4 — no
floats anywhere in this domain.

## Purchasable vs. publicly visible are different scopes

P4's `scopePublic()` (excludes `draft`/`scheduled`) answers "can a
customer see this competition at all?" A narrower question — "can a
customer buy an entry into it right now?" — needs its own scope:

```php
scopePurchasable(): only status === Active
```

`sold_out`/`closed`/`draw_pending`/`draw_in_progress`/`drawn`/`completed`
are all still publicly *visible* (P4) but none of them should accept a
new entry. Applied both when adding to cart and (re-checked) at order
creation, since a competition can go sold-out between those two moments.

## `max_entries_per_user` is enforced now — it's not ticket allocation

P2 already added `competitions.max_entries_per_user` specifically for
this purpose. Checking a requested cart quantity against a stored column
on the competition itself is a simple bounds check, not inventory
tracking or concurrency-sensitive ticket reservation (that's P7). A
separate, deliberately generous **per-line hard cap of 100** applies
regardless of `max_entries_per_user` (which may be null/unlimited) —
purely to block an obviously-abusive single request, not a real business
rule.

## Orders: `subtotal` *and* `total`, even though they're equal today

No discount/promotion/tax logic exists (explicitly out of scope — P5
removes the P1 mock promo-code UI entirely, since a "discount" the
server never applies would be actively misleading). `subtotal` and
`total` are stored as separate columns anyway: they represent different
concepts (pre- and post-adjustment) that a later phase can legitimately
make diverge without a schema change, even though P5 always sets them
equal.

`orders.reference` — a 6-digit `RG-XXXXXX` string (same format the P1
mock already used, for visual continuity), generated server-side and
enforced unique by a **database** unique constraint (retried on the rare
collision, not trusted to application logic alone). Never derived from
the auto-increment `id`.

`orders`/`order_items` **are** soft-deleted — unlike carts, these are
financial records that need the same audit-preservation treatment P2
gave `competitions`/`prizes`.

## Order status: full future lifecycle, only `pending` reachable in P5

```php
enum OrderStatus: string {
    Pending          // set by P5 — order created, awaiting payment
    PaymentPending   // P6
    Paid             // P6
    Processing       // P7 (ticket allocation in progress)
    Completed        // P7 (tickets allocated)
    Cancelled        // not set by any P5 code path (no cancel endpoint yet)
    Failed           // P6 (payment failed)
    Refunded         // P6/P7
}
```

Same reasoning as `CompetitionStatus` (P2): a plain string column + PHP
enum, not a MySQL `ENUM` type — adding a status later is a code change,
not a migration. P5 order creation only ever sets `Pending`; every other
case exists so P6/P7 don't need a schema change to reach them.

## Order items: snapshot enough to stay meaningful, not more

`competition_title` (string snapshot) + `unit_price`/`currency`/
`line_total` (snapshots) mean an order stays readable even if the
competition's title changes or it's later soft-deleted. `prize_id` is
included (nullable, `nullOnDelete`) since P2 already models
`Competition hasMany Prize` and a historical "what could you have won"
reference is cheap to keep — but no separate prize *snapshot* fields
(title/value) were added; that's the kind of speculative extra
normalization P2/P4 already decided against for content that doesn't
need independent historical drift protection here.

## Deliberately not built

No payment fields on `orders` (P6). No ticket numbers/allocation
anywhere (P7). No discount/coupon table. No admin/Filament order
management — same reasoning as P4's missing admin competition endpoints:
no role/permission system exists yet to gate it on.
