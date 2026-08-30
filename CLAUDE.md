@AGENTS.md

## Design system

RaffleGuru has a documented design system — see [`DESIGN_SYSTEM.md`](DESIGN_SYSTEM.md).

- All brand tokens (colour, type scale, radius, elevation, motion) live in the
  `@theme` block of `src/app/globals.css`. Never hardcode a hex value or a
  per-component `text-*` size — use a token or a `.t-*` class.
- Reusable UI lives in `src/components/{ui,layout,media,competition,category,winner}`.
  Compose from `Button`, `Badge`, `ImageFrame`, `Container`, `Section`,
  `SectionHeading`, form primitives, etc. before writing bespoke markup.
- `src/components/sections/` holds homepage-specific section compositions.
- Mock/static content only — `src/data/homepageMockData.ts`. No API calls,
  auth, payments or DB in this prototype.
