# P4 — Competition Management API

Public, read-only competition browsing on top of the P2 schema. No
authenticated/admin management endpoints exist yet (see "Why no admin
endpoints" below).

## Endpoints

| Method & path | Auth | Notes |
|---|---|---|
| `GET /api/v1/competitions` | public | paginated, filterable, sortable |
| `GET /api/v1/competitions/{slug}` | public | slug-based lookup, includes `prizes` |

## Public visibility rules

Enforced by `Competition::scopePublic()`, applied to **every** public
query (list and detail) — never bypassable via a query parameter:

| Status | Publicly visible? |
|---|---|
| `draft` | No — unpublished |
| `scheduled` | No — approved but not yet live |
| `active` | Yes |
| `sold_out` | Yes |
| `closed` | Yes |
| `draw_pending` | Yes |
| `draw_in_progress` | Yes |
| `drawn` | Yes |
| `completed` | Yes |

Soft-deleted competitions are excluded automatically (Eloquent's default
`SoftDeletes` behavior — no extra code needed). Nothing here mutates a
competition's status based on `starts_at`/`ends_at`/`draw_at` — that
lifecycle automation is P8's job; P4 only reads the stored `status`.

A `status=draft` (or any non-public value) query parameter is accepted by
validation (it's a real enum value) but still yields zero rows — the
public scope is applied before the filter, so there's no way to reach
non-public data through this parameter.

## Query parameters (`GET /api/v1/competitions`)

| Param | Type | Notes |
|---|---|---|
| `search` | string | matches `title` or `short_description` |
| `category` | string | exact match |
| `status` | enum | validated against `CompetitionStatus`; see above re: draft/scheduled |
| `featured` | boolean | |
| `sort` | `ending_soon` \| `newest` \| `price` \| `featured` | allow-listed — never an arbitrary column |
| `direction` | `asc` \| `desc` | overrides the sort key's default direction |
| `per_page` | int, 1–24 | default 12 |
| `page` | int, ≥1 | |

Response is Laravel's standard paginator shape: `{data: [...], links:
{...}, meta: {current_page, last_page, per_page, total, ...}}`.

## Serialization

`CompetitionResource`/`PrizeResource` (`app/Http/Resources/`) explicitly
allow-list every field — same convention as P3's `UserResource`. Notably:

- `skill_question` never includes `answer_index` — the whole point of a
  skill-testing question is defeated if the public API hands out the
  answer. See `docs/SCHEMA_DESIGN_P2.md`.
- No timestamps beyond the public `starts_at`/`ends_at`/`draw_at` — no
  `created_at`/`updated_at`/`deleted_at`.
- Money stays in minor units (pence) exactly as stored — the frontend
  divides by 100 before formatting (see `adaptCompetition`).

## Why no admin/management endpoints

There's no role/permission concept anywhere in this architecture yet (no
`is_admin` flag, no policies, no Filament) — P4 only builds the
customer-facing read side. Writing competitions/prizes is a P11
(Filament admin) concern, once there's an actual authorization boundary
to gate it on. Building authenticated CRUD now would mean inventing an
authorization system just to protect it, which is exactly the kind of
premature architecture P4 was told to avoid.

## Frontend integration

`apps/web/src/lib/competitions/{client,types,adapter}.ts`:

- `client.ts` — the only module that calls these endpoints, following
  the same shape as `@/lib/auth/client` (P3). Picks between the public
  origin (browser) and an internal Docker route (Server Components —
  see `src/lib/config.ts::resolveApiBaseUrl`) automatically.
- `types.ts` — types matching the real API response exactly (no mock
  fields, no `answer_index`).
- `adapter.ts` — maps a real `ApiCompetition` onto the *existing* mock
  `Competition` view-model type (`@/data/competitions`), so
  `CompetitionCard`/`CompetitionGrid`/`CompetitionFacts`/`CompetitionInfo`/
  `EntrySelector`/etc. needed **zero changes** — only the data source
  feeding them changed. Documented, known limitations of that mapping
  (no ticket engine yet, so `soldPct` is always 0; the answer-checking
  question UI can no longer show "correct" since the API never sends the
  answer) are commented directly in `adapter.ts`.
- The `/competitions` listing page fetches from the real API with
  loading/error/empty states and real pagination (no more loading the
  full table into memory client-side); `/competitions/[slug]` is now a
  dynamically-rendered page (not statically generated at build time —
  competition data can change without a rebuild) with a real 404 for
  unknown/draft/soft-deleted slugs.
- Cart/checkout behavior (adding a mock entry to the P1 basket) is
  untouched — P4 didn't build or change any of that, it's pre-existing
  prototype behavior, not a new purchase flow.

### A real bug found and fixed during this work

The `[slug]` route originally had a `loading.tsx` for a nicer loading
state. That's incompatible with an accurate 404: Next.js starts
streaming the response (committing an HTTP 200) as soon as
`loading.tsx` renders, before the async page component has resolved —
so a later `notFound()` call can no longer change the status code the
client already received. `loading.tsx` was removed from this route in
favor of a correct 404 for unknown/draft/deleted competitions, verified
against a production build, not just `next dev`.

## Security review checklist (verified, not just claimed — see PHPUnit
suite `tests/Feature/Competition/`)

- [x] Draft competitions excluded from listing and detail
- [x] Scheduled competitions excluded
- [x] Soft-deleted competitions excluded from both listing and detail
- [x] Unknown slug → 404 (verified live through Nginx, both dev and a
      production build)
- [x] `answer_index` never present in any response (listing or detail)
- [x] No internal fields (`deleted_at`, `created_at`, `updated_at`) leak
- [x] Invalid `sort`/`status`/`page`/`per_page` rejected with `422`
- [x] `sort` cannot select an arbitrary column (allow-list)
- [x] `per_page` capped at 24
- [x] `status=draft` (or any non-public value) as a filter cannot reach
      non-public rows
- [x] Prizes eager-loaded — no N+1 (query-count assertion in
      `CompetitionListTest`)
