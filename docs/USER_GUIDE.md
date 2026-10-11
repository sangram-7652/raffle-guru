# User Guide — Customer Journey

What an actual customer can do on RaffleGuru today, end to end, and
which backend phase (P3–P10) implements each step. This is the
customer-facing counterpart to the per-phase API docs
(`AUTHENTICATION.md`, `COMPETITIONS_API.md`, `ORDERS_API.md`,
`PAYMENTS_API.md`, `TICKET_ENGINE_API.md`, `DRAW_ENGINE_P9.md`,
`WINNER_CLAIM_P10.md`) — read those for implementation detail; this
document is the "what does a customer actually see and do" walkthrough.
See [`ROADMAP.md`](ROADMAP.md) for what's built vs. still ahead (P11
admin UI, P12 observability).

Everything described here is real, working behavior against the live
API — none of it is mock data. The one exception called out below is
`/how-it-works`, which contains illustrative sample copy (a "skill
question" and a "free postal entry" route) that isn't backed by any
API field yet.

## 1. Create an account

| Page | Route | Backed by |
|---|---|---|
| Register | `/register` | `POST /api/v1/auth/register` |
| Login | `/login` | `POST /api/v1/auth/login` |
| Forgot password | `/forgot-password` | `POST /api/v1/auth/forgot-password` |
| Reset password | `/reset-password?token=...&email=...` | `POST /api/v1/auth/reset-password` |
| Verify email | `/verify-email` | signed link, followed automatically after registering |
| Account home | `/account` | `GET /api/v1/auth/user` |

- Auth is cookie-session based (Sanctum), not a token you copy around —
  once logged in, the browser just carries a session cookie.
- Email verification and password-reset emails aren't sent by a live
  mail provider yet in development — they're written to the API's log
  file. In production this needs a real mail transport configured (see
  `apps/api/.env.example`).
- `/account` requires login — an unauthenticated visitor is redirected
  to `/login?redirect=/account`. From there it links out to order
  history, `/account/entries`, and `/account/winner`.
- There's no guest checkout: browsing and viewing competitions is
  public, but adding to a cart requires being logged in.

## 2. Browse competitions

| Page | Route | Backed by |
|---|---|---|
| Listing | `/competitions` | `GET /api/v1/competitions` |
| Detail | `/competitions/[slug]` | `GET /api/v1/competitions/{slug}` |

- Filter/sort by search text, category, status, featured flag, ending
  soonest / newest / price / featured — all server-side, paginated.
- Every listed competition shows the prize, its value, the entry
  price, the entry cap (`max_entries`), and the draw date/time — this
  is real, not placeholder copy.
- A competition only accepts new entries while its status is `active`.
  It can still be **viewed** (not entered) once `sold_out`, `closed`,
  or further along — see the status table below.
- Once a competition reaches `drawn`, its page shows the winning
  ticket number and the exact draw timestamp — this is published
  automatically, the same way a physical raffle would announce a
  result; there's no separate "results" page for it yet.

### Competition status, as a customer sees it

| Status | Can I still buy tickets? | What it means |
|---|---|---|
| `active` | Yes | On sale |
| `sold_out` | No | Every ticket allocated before the close date |
| `closed` | No | Close date passed before selling out |
| `draw_pending` / `draw_in_progress` | No | Waiting for / running the draw |
| `drawn` | No | Winning ticket number published |
| `completed` | No | Prize delivered, competition fully wrapped up |

Statuses move automatically on the server (no admin has to flip a
switch) — see `COMPETITION_LIFECYCLE_P8.md`.

## 3. Add tickets to your cart

| Page | Route | Backed by |
|---|---|---|
| Cart | `/cart` | `GET/POST/PATCH/DELETE /api/v1/cart...` |

- One line per competition — adding the same competition again
  increases that line's quantity rather than creating a duplicate row.
- Quantity is capped per request (100) and, when the competition
  defines a `max_entries_per_user`, against that limit too — the cart
  will reject a quantity that would put you over it.
- Price is always what the server currently has on file for that
  competition — nothing the browser sends about price is ever trusted.
- Requires login; an unauthenticated visitor hitting `/cart` has
  nothing to see (no guest cart exists).

## 4. Check out and pay

| Page | Route | Backed by |
|---|---|---|
| Checkout | `/checkout` | `POST /api/v1/orders` |
| Payment | (same page, Razorpay Checkout overlay) | `POST /api/v1/orders/{ref}/payment`, `POST /api/v1/payments/verify` |
| Order success | `/order/success` | `GET /api/v1/orders/{reference}` |

1. Placing the order re-validates every line against the live
   competition state — if anything in your cart sold out or closed
   between adding it and checking out, the **whole order is rejected**
   with a message naming which item, and your cart is left intact so
   you can fix it and retry. Nothing is ever partially ordered.
2. The order starts as `pending` (nothing charged yet), gets a public
   reference like `RG-482915`, and payment opens via Razorpay
   Checkout.
3. Card/UPI/bank details are entered directly into Razorpay's own
   checkout UI — this site never sees or stores card numbers.
4. If a payment attempt fails, the order stays payable and you can
   retry — it doesn't get cancelled or force you to rebuild the cart.
5. On success, the order becomes `paid` and ticket allocation is
   queued automatically (usually seconds, not instant) — the order
   success page polls for a few seconds and shows real ticket numbers
   as soon as they're allocated, with an honest "allocation in
   progress" message rather than a fake number in the meantime.

## 5. See your tickets

| Page | Route | Backed by |
|---|---|---|
| My entries | `/account/entries` | `GET /api/v1/entries` |

- Every paid order becomes one **entry** per competition line, and
  each entry lists its **ticket numbers** — sequential per
  competition, never the same as any internal database id.
- This list is read-only: there's nothing to edit, cancel, or
  transfer here.

## 6. The draw

There is no customer action here — draws run automatically on the
published `draw_at` time, once a competition is `closed`/`sold_out`.
The result — winning ticket number and timestamp — appears on that
competition's own page (`/competitions/[slug]`) the moment it's final;
no livestream, RNG method, or internal draw mechanics are exposed
through the API. (`/how-it-works`'s mention of a livestream and an
on-camera draw is illustrative marketing copy, not something the
backend produces yet.)

## 7. If you won

| Page | Route | Backed by |
|---|---|---|
| My win | `/account/winner` | `GET /api/v1/account/winner`, `GET/POST .../claim` |

The only thing a winner ever actively does is **submit a claim** —
everything else on this page is status you watch update as RaffleGuru
staff process it server-side.

```
you're identified as the winner
        │
        ▼
  (page shows: "You won!" — submit a claim)
        │  you submit a claim (optional free-text note)
        ▼
  verification pending  →  verified  →  claim approved
        │
        ▼
  delivery pending → (shipped, for physical prizes) → delivered
        │
        ▼
     completed
```

- If you haven't won anything, `/account/winner` just shows an empty
  state — no error, nothing to do.
- Verification here means RaffleGuru staff confirming your identity —
  there's no automated KYC/ID-check step yet; "verified" is a manual
  server-side decision, not something you self-serve.
- Prize delivery is manual and operator-driven — cash by bank
  transfer, physical prizes arranged directly with you. There's no
  courier-tracking integration; any tracking reference shown is
  entered by a person, not fetched live from a shipper.
- A rejected claim is currently a dead end — there's no resubmit
  button. If that happens, this needs a manual follow-up outside the
  app (see `WINNER_CLAIM_P10.md` §19).
- You will never see, or be able to trigger, another customer's win —
  every winner/claim lookup is scoped strictly to your own account.

## 8. Content / legal pages (static)

`/how-it-works`, `/faq`, `/about`, `/contact`, `/terms`, `/privacy`,
`/cookies`, `/responsible-play`, `/competition-rules`, `/winners` are
static informational pages — not tied to a customer's own account or
order data. Treat their specifics (e.g. "free postal entry", the
skill-question mechanic) as marketing copy to be reconciled with the
real rules before launch, not as documentation of implemented backend
behavior.

## Full route reference

| Route | Requires login | Purpose |
|---|---|---|
| `/` | No | Home |
| `/competitions`, `/competitions/[slug]` | No | Browse |
| `/cart` | Yes | Basket |
| `/checkout` | Yes | Place order + pay |
| `/order/success` | Yes | Post-payment confirmation |
| `/account` | Yes | Account overview: entry, ticket, draw and win counts, active competitions, upcoming draws, account status, recent orders |
| `/account/details` | Yes | Name, email and verification status; resend verification email |
| `/account/entries` | Yes | Ticket numbers |
| `/account/winner` | Yes | Win status + claim |
| `/login`, `/register`, `/forgot-password`, `/reset-password`, `/verify-email` | No | Auth |
| `/how-it-works`, `/faq`, `/about`, `/contact`, `/winners`, `/terms`, `/privacy`, `/cookies`, `/responsible-play`, `/competition-rules` | No | Static content |

## What's not built yet

- **No admin/staff UI** (P11) — every "operator" step above (contact
  the winner, verify them, approve a claim, mark a prize shipped) is a
  backend service with no interface yet; it has to be driven by
  someone calling the domain service directly or via `artisan
  tinker`/a future Filament resource.
- **No notifications** — nothing emails or texts a customer when they
  win, when their claim is approved, or when a prize ships. The
  underlying audit events exist (see `WINNER_CLAIM_P10.md` §17) but
  nothing is wired to send anything yet.
- **No refund-initiation UI** — refunds only happen from the Razorpay
  dashboard and are passively reflected here.
- **No order cancellation** — a `pending` order with a failed/abandoned
  payment just sits there; there's no "cancel my order" button.
