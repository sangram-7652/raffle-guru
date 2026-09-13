# P2 Schema Design — Users, Competitions, Prizes

Written before the migrations, per the P2 process. This is the reasoning;
`docs/PRODUCTION_ARCHITECTURE.md` gets the durable summary afterward.

## Domains and ownership

- **User** — a person with an account. Owns nothing yet in P2 (no orders,
  no entries exist yet). Exists now purely so P3 Authentication has
  somewhere to attach.
- **Competition** — a raffle: one title, one entry price, one ticket cap,
  one draw date, a lifecycle status. Owned by the platform, not by a
  specific admin user (no `created_by` — that's a P11 Filament/audit
  concern, not needed for the 3-domain foundation).
- **Prize** — what a Competition's winner(s) receive. **`Competition
  hasMany Prize`**, not `hasOne` — see below.

## Why hasMany, not hasOne, for Prize

The schema for "one prize" and "many prizes" is identical
(`prizes.competition_id` either way) — the only difference is whether a
`unique()` constraint pins it to exactly one row. This industry
routinely runs multi-tier prizes ("1st: car, 2nd: cash, 3rd–10th:
consolation"), and P0's module map already lists Prize management as
its own future capability. Choosing `hasMany` now costs nothing (every
current mock competition still gets exactly one `Prize` row via the
seeder/factory) and avoids a constraint that would have to be dropped
later. `hasOne` would have been the wrong default to lock in.

## Not blindly copying the frontend mock

The mock `Competition` type in `apps/web/src/data/competitions.ts` flattens
competition and prize fields into one object, and includes UI-only or
duplicated content. Specifically **not** carried into the schema as-is:

- `rules` and `faqs` on the mock type are the *same constant* for every
  single competition (`standardRules`, `standardFaqs` — never overridden).
  They aren't per-competition data at all; they're generic platform copy
  that already has a home (`apps/web/src/data/legal.ts`, the `/faq` page).
  **No `competition_rules` / `competition_faqs` table** — see Deferred.
- `cashAlternative` (the display string `"or £58,000 cash"`) is derived
  copy, not data — only `cashAlternativeValue` is stored; the frontend (or
  a future API resource) can format the string.
- `images` stays on `competitions`, not `prizes` — see below.
- `question` (skill-testing question) **is** genuine per-competition data
  (UK prize-competition law requires a skill element for a paid-entry
  draw) and is preserved, just reshaped as a JSON column — see below.

## `images` on Competition, not Prize

Every current competition has exactly one prize, and the frontend already
reads `images` at the competition level throughout (`CompetitionGallery`
takes the competition's `images`, not a nested prize object). Duplicating
image storage onto `prizes` now, before any competition actually has more
than one prize, would just create a second place images can drift out of
sync. Deferred: once P4 needs a competition with multiple visually
distinct prizes, add `prizes.images` then, driven by a real requirement
instead of a guess now.

## `skill_question` as JSON on Competition, not a child table

One question per competition today, no independent lifecycle, no shared
question bank. A `competition_questions` table would be justified if a
competition could have multiple questions or questions needed to be
reused/versioned — neither is true yet. Stored as a JSON column
(`prompt`, `options`, `answer_index`); nullable, since a competition run
under a genuine free-entry route may not need one.

**Compatibility flag for P4**: `answer_index` must never be included in a
public API response for this column — the API resource layer has to
strip it before serializing, or the "skill" question stops being a
meaningful skill test. Noting this now so it isn't missed when the read
API is built.

## Status: PHP backed enum + plain string column, not a MySQL `ENUM`

`app/Enums/CompetitionStatus.php`, a string-backed enum with the full
future lifecycle (`draft`, `scheduled`, `active`, `sold_out`, `closed`,
`draw_pending`, `draw_in_progress`, `drawn`, `completed`) even though only
a couple of these are reachable before P8 builds the lifecycle engine.
The column itself is a plain `VARCHAR`, not a MySQL native `ENUM` type:
adding a new MySQL `ENUM` value later means an `ALTER TABLE`; adding a new
PHP enum case is a one-line code change with no migration. Laravel casts
the column to the enum natively (`'status' => CompetitionStatus::class`).

## Money: integer minor units, not `DECIMAL` or `FLOAT`

Every monetary column (`entry_price`, `prizes.value`,
`prizes.cash_alternative_value`) is `unsignedInteger`, storing pence (or
whatever the minor unit is for `currency`). Never `FLOAT`/`DOUBLE` —
binary floating point cannot represent money exactly. Chose integer minor
units over `DECIMAL(10,2)` because it matches how P6 Payments will
actually talk to a card processor (Stripe et al. bill in minor units
already), so there's no conversion step at the payment boundary later.
`unsignedInteger` tops out around 4.29bn minor units (~£42.9M) — no
realistic prize value gets close.

`currency` is `CHAR(3)` (ISO 4217, e.g. `GBP`), defaulted to `GBP` to
match the current UK-only mock data, without hardcoding GBP into the
schema itself.

## IDs: keep auto-increment, don't introduce UUIDs

Laravel's existing `users` migration already uses auto-increment bigint
IDs — Phase 2 explicitly said not to change that casually, so `users`
stays as-is. For consistency (and because there's no multi-tenant/
distributed-ID/external-exposure requirement), `competitions` and
`prizes` also use auto-increment IDs. Public URLs already use
`competitions.slug`, not the ID, so there's no "don't leak a sequential
ID" pressure pushing toward UUIDs either.

## Deletion: soft deletes on all three tables

None of Orders/Tickets/Draws/Winners exist yet, but they will reference
`users`, `competitions`, and `prizes` by ID once they do. A hard delete
today, before those tables exist, seems harmless — but the schema
decision made now is what determines whether that history is even
possible to preserve later. All three tables get `deleted_at`
(`SoftDeletes` trait). `prizes.competition_id` additionally uses
`restrictOnDelete()` (MySQL's default anyway, made explicit): a rare
hard-delete attempt on a competition that still has prize rows fails
loudly rather than silently cascading prize data away.

## What was deliberately not added

- No `created_by`/admin-owner column on `competitions` — no Filament/audit
  requirement yet (P11).
- No `categories` table — `category` stays a plain indexed string column;
  a real taxonomy table is a P4 Competition Management concern once
  categories need independent management (icons, ordering, etc.), not a
  P2 foundation requirement.
- No `competition_rules` / `competition_faqs` tables — see above, the
  current content isn't per-competition data at all.
- No per-prize images — see above.
