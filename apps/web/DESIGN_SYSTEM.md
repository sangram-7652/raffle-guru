# RaffleGuru Design System

The reusable visual language for the RaffleGuru platform: a premium
**white + black + gold** identity built around the RaffleGuru Gold logo
(`public/brand/raffle-guru-gold.png`). The site is **white/light-first** —
dark (`--color-ink`) is used selectively, for the announcement bar, the
final CTA band and the footer only. **The homepage is the source of
truth** — this system codifies what already shipped, it does not redesign it.

Single rule: **consume tokens and primitives, never hardcode.** No hex values,
no hand-picked font sizes, no bespoke button/badge markup in feature code.

---

## 1. Design tokens

All tokens live in the `@theme` block of [`src/app/globals.css`](src/app/globals.css).
That block is the only place to edit the brand. Tailwind v4 turns each token
into utilities automatically (`--color-gold` → `bg-gold`, `text-gold`, …).

### Colour

| Token | Value | Use |
|---|---|---|
| `--color-gold` | `#c89b3c` | **Primary brand.** Fills/borders, and text **only on dark (`ink`) surfaces** — fails contrast on white/cream. |
| `--color-gold-light` | `#e5c76b` | Lighter gold — fills/glow, dark surfaces only. |
| `--color-gold-pale` | `#ead9ab` | Palest champagne — decorative glow accents only. |
| `--color-gold-700` | `#8f6a22` | Dark gold — **text/icons on white/cream/panel** (≥4.3:1, AA). |
| `--color-paper` | `#ffffff` | Page background |
| `--color-card` | `#ffffff` | Raised surfaces |
| `--color-panel` | `#fafaf8` | Chips, hovers, inset form fields |
| `--color-mist` | `#f7f4ed` | Alternating tinted (cream) sections |
| `--color-track` | `#f3efe5` | Progress / slider track |
| `--color-line` | `#e8e2d5` | Hairline borders |
| `--color-heading` | `#111111` | Headings, strong text |
| `--color-muted` | `#555555` | Secondary body text (~7.5:1 on white) |
| `--color-muted-light` | `#777777` | Tertiary — large text / icons only (~4.5:1) |
| `--color-ink` | `#080808` | Selective dark sections: announcement bar, final CTA, footer |
| `--color-success` / `-600` / `-700` | `#12a150` / `#0f8a44` / `#0a6b35` | Positive status. `-700` is for solid fills needing white text. |
| `--color-warning` / `-600` | `#c2410c` / `#9a3412` | Caution status |
| `--color-error` / `-600` / `-700` | `#dc2626` / `#b91c1c` / `#991b1b` | Error status. `-700` is for solid fills (e.g. `danger` button) needing white text. |
| `--color-live` | `#e11d48` | "Draw is live" pulse |

**The gold/gold-700 split is load-bearing** — base `gold` is only ~2.5:1 on
white (fails even large-text/UI contrast), so any gold *text* or *icon* on a
white/cream/panel surface must use `gold-700`. Base `gold` stays reserved for
fills (`.brand-gradient`, `bg-gold`, always paired with `text-ink`), borders,
and text/icons that sit on a dark (`ink`) surface, where it has ~7.8:1.
`gold-light`/`gold-pale` are dark-surface-only (even worse contrast on white).

Gradient: `.brand-gradient` (gold-light → gold) is a **fill**, always paired
with `text-ink` or `text-white`, never `text-gold`. `.text-gradient` exists
for gradient text on dark surfaces only — not currently used anywhere, since
no on-white use of it would meet contrast. Status is **never colour-only** —
every badge/message carries an icon or label.

### Typography

Fonts: **Sora** (`--font-display`) for headings, **Inter** (`--font-sans`) for body,
loaded via `next/font` in `src/app/layout.tsx`.

Responsive scale as `.t-*` classes (calibrated to the homepage's original
breakpoint values — `sm` 40rem, `md` 48rem, `lg` 64rem). **Set size with these,
never per-component `text-*`.**

| Class | Size (base → sm → md/lg) | Line height | Weight | Role |
|---|---|---|---|---|
| `.t-display` | 2.75 → 3.75 → 4.2rem | 0.98 | 800 | Hero headline |
| `.t-h1` | 2.25 → 3 → 3.75rem | 1.02 | 800 | Page / CTA headline |
| `.t-h2` | 1.875 → 2.25 → 3rem | 1.05 | 800 | Section heading |
| `.t-h3` | 1.25rem | 1.25 | 800 | Card / feature title |
| `.t-h4` | 1.125rem | 1.3 | 800 | Small card title |
| `.t-body-lg` | 1.125rem | 1.6 | — | Lede / intro copy |
| `.t-body` | 1rem | 1.6 | — | Default copy |
| `.t-body-sm` | 0.875rem | 1.6 | — | Dense copy, list items |
| `.t-caption` | 0.75rem | 1.5 | — | Meta, helper text |
| `.t-eyebrow` | 0.75rem | 1 | 600 | Uppercase kicker (0.22em tracking) |
| `.t-label` | 0.8125rem | 1.4 | 600 | Form / meta labels |

All heading classes bake in `letter-spacing: -0.025em`. Colour is applied
separately (`text-heading` / `text-muted` / `text-white`).

### Spacing

Tailwind's default 4px scale (`space-1` = 4px … `space-24` = 96px) already
matches the required rungs — it is **not** redefined. Section vertical rhythm is
the `.section-y` (5rem → 7rem) / `.section-y-sm` (2.5rem → 4rem) helpers, applied
by `<Section>`.

### Radius

Tailwind defaults (`rounded-sm`…`rounded-2xl`, `rounded-full`) plus two brand rungs:

| Token | Value | Use |
|---|---|---|
| `rounded-card` (`--radius-card`) | 1.25rem | Standard card / grid tile |
| `rounded-panel` (`--radius-panel`) | 1.75rem | Large feature panels, hero visual |
| `rounded-hero` (`--radius-hero`) | 2rem | Full-bleed CTA block |

### Elevation

| Token | Use |
|---|---|
| `--shadow-e1` / `.card-shadow` | Resting card |
| `--shadow-e2` / `.card-shadow-lg` | Floating card, hero banner |
| `--shadow-e3` | Hero prize visual (highest) |
| `--shadow-glow` | Pink hover glow on competition cards |
| `--shadow-cta` | Resting shadow under primary buttons |
| `--shadow-ink` | Dark button on the gradient CTA |

Two resting levels only — elevation is used sparingly to keep the premium feel.

### Motion

`--animate-rise` / `--animate-fade` / `--animate-float` / `--animate-shimmer`,
easing `--ease-brand`. All animation is disabled under
`@media (prefers-reduced-motion: reduce)` (global rule at the end of `globals.css`).
`<Reveal>` drives scroll-in reveals via a single IntersectionObserver.

---

## 2. Component organisation

```
src/components/
  ui/            Button, Badge, ProgressBar, Countdown, StatCard, Icon, Reveal
    form/        FormField, Label, Input, Textarea, Select, Checkbox, Radio,
                 HelperText, ErrorMessage  (+ styles.ts shared field styling)
  layout/        Container, Section, Stack, SectionHeading, Header, Footer
    navigation/  DesktopNavigation, MobileNavigation, NavigationItem
    footer/      FooterColumn, SocialLinks
  media/         ImageFrame, Logo
  competition/   CompetitionCard, CompetitionGrid
  category/      CategoryCard
  winner/        WinnerCard, WinnerSection
  account/       AccountShell, AccountNav, AccountPageHeading, SummaryCard,
                 ActiveCompetitionCard, UpcomingDrawsList, AccountStatusCard, VerificationBanner
  sections/      Homepage section compositions (Hero, TrustStrip, …)
```

`ui`, `layout`, `sections` expose barrel `index.ts` files. Import primitives via
the `@/` alias.

### Primitives

- **`Button`** — `variant`: primary | secondary | outline | ghost | danger;
  `size`: sm | md | lg; `block`, `loading`, `disabled`. Renders `<a>` when `href`
  is set, otherwise a real `<button>`. Visible focus ring on every variant.
- **`Badge`** — `tone`: brand | neutral | featured | ending-soon | live | sold-out
  | new | winner | success | warning | error. Optional leading icon; `live`
  gets a pulsing dot. Every badge has a text label.
- **`ProgressBar`** — animated "% sold" bar, fills once on scroll-in. Presentational.
- **`Countdown`** — presentational only (Phase 1 = no backend). `variant`:
  inline | blocks. Ticks client-side via `useSyncExternalStore` (SSR-safe), takes
  a static `target` date. Not currently placed on the homepage.
- **`StatCard`** — value + label KPI; `plain` (hero strip) or `card`.
- **`Icon`** — single inline-SVG set, `currentColor`, `aria-hidden`.

### Layout

- **`Container`** — the one content column: `max-w-7xl` + `px-4 sm:px-6 xl:px-8`.
- **`Section`** — full-width band: `surface` (paper | mist | ink), `spaceY`
  (default | sm | none), `bordered`, auto-wraps children in `Container`.
- **`Stack`** — thin flex helper (`direction`, `gap`, `align`, `justify`).
- **`SectionHeading`** — eyebrow + `.t-h2` title + lede, left or centre.

### Media

- **`ImageFrame`** — the only way to render a bounded image. Fixed `ratio`
  (`4/3` `5/4` `16/9` `3/2` `1/1` `21/9`), `radius`, `object-fit`, native
  lazy-load (`priority` to opt out). Prevents unpredictable cropping.

### Forms (ready for login / checkout / account — not used on the homepage yet)

`<FormField label error hint success required>` wires a `Label`, the control, and
a `HelperText` / `ErrorMessage` together with matching `id` /
`aria-describedby` / `aria-invalid`. Controls (`Input`, `Textarea`, `Select`,
`Checkbox`, `Radio`) share `styles.ts` and support default / focus / error /
success / disabled states.

---

## 3. Responsive & accessibility

- One breakpoint system (Tailwind `sm` 40rem / `md` 48rem / `lg` 64rem /
  `xl` 80rem). Audited 320 → 1920px: no horizontal overflow; grids collapse
  1 → 2 → 3/4; the mobile menu is its own full-screen layout, not a squashed bar.
- Keyboard: every interactive element is reachable, has a visible
  `focus-visible` ring, and uses correct semantics (`<button>` vs `<a>`).
  Mobile menu closes on `Escape` and locks body scroll.
- `prefers-reduced-motion` honoured globally.
- Contrast: the site is white/light-first — gold **text/icons** on
  `paper`/`card`/`panel`/`mist` must use `gold-700` (base `gold` fails at
  ~2.5:1). Text painted *on top of* a gold fill (`.brand-gradient`, `bg-gold`)
  must use `text-ink`, never `text-white`. On the selective dark (`ink`)
  sections — announcement bar, final CTA, footer — base `gold` is correct
  for text (~7.8:1) and `border-line`/`text-heading`/`text-muted` must not be
  used (they're tuned for the light surfaces and are illegible on black; use
  explicit `text-white`/`border-gold`/`20` instead).
