/**
 * RaffleGuru — Competition types + generic content
 * ------------------------------------------------------------------
 * The `/competitions` listing and detail pages are backed by the real
 * API (see @/lib/competitions/{client,adapter}.ts) as of P4 — this file
 * no longer holds sample competition data, only the shared `Competition`
 * view-model type (which the adapter maps real API responses onto, so
 * CompetitionCard/CompetitionGrid/CompetitionFacts/etc. don't need to
 * change) plus content that was always generic rather than
 * per-competition (`standardRules`/`standardFaqs` — see
 * docs/SCHEMA_DESIGN_P2.md for why those were never stored per-row).
 */

/**
 * "closed"/"draw-pending" (P8) distinguish "no longer taking entries,
 * not yet drawn" from "drawn" (the draw has actually happened) — never
 * collapse these into "drawn", which would falsely claim a draw
 * occurred. See @/lib/competitionAvailability.ts and
 * docs/COMPETITION_LIFECYCLE_P8.md.
 */
export type CompetitionStatus =
  | "live"
  | "ending-soon"
  | "sold-out"
  | "closed"
  | "draw-pending"
  | "drawn";

export type CompetitionQuestion = {
  prompt: string;
  options: string[];
  /** index of the correct option — UI only, not validated anywhere real.
   * Always -1 for real (API-backed) competitions: the API never sends
   * the answer, so there's nothing to check client-side any more. */
  answerIndex: number;
};

export type Competition = {
  // ---- fields shared with the homepage card ----
  id: string;
  slug: string;
  title: string;
  blurb: string;
  image: string;
  badge: string;
  category: string;
  entryPrice: number;
  cashAlternative?: string;
  soldPct: number;
  drawLabel: string;
  // ---- listing / detail fields ----
  prize: string;
  prizeValue: number;
  cashAlternativeValue?: number;
  images: string[];
  totalEntries: number;
  drawDate: string; // ISO 8601
  status: CompetitionStatus;
  featured: boolean;
  description: string[];
  prizeDetails: string[];
  rules: string[];
  question: CompetitionQuestion;
  faqs: { q: string; a: string }[];
  /** P9 — only ever set once status is actually "drawn". The public
   * winning ticket number, nothing about who holds it (see
   * docs/DRAW_ENGINE_P9.md "Public API behavior" / P10 boundary). */
  winningTicketNumber?: number;
};

/** Generic platform copy — identical for every competition, not fetched
 * per-competition. See docs/SCHEMA_DESIGN_P2.md. */
export const standardRules = [
  "One entry equals one ticket. Every competition has a published maximum number of tickets.",
  "The draw takes place on the date shown once all tickets are sold or the closing time is reached, whichever is first.",
  "Winners are selected at random using a verified random number generator and announced on our live stream.",
  "You must be 18 or over and a UK resident to enter. Proof of ID and address may be requested before a prize is released.",
  "Where offered, the cash alternative is chosen by the winner within 14 days of the draw.",
  "Full terms are set out in the Competition Rules.",
];

export const standardFaqs = [
  {
    q: "How is the winner chosen?",
    a: "Every sold ticket is assigned a number. On the draw date a verified random number generator selects the winning number, live on camera.",
  },
  {
    q: "What happens if not all tickets sell?",
    a: "The draw still goes ahead on the published date. The prize is guaranteed regardless of how many tickets are sold.",
  },
  {
    q: "Can I take cash instead of the prize?",
    a: "Where a cash alternative is shown, the winner can choose it instead of the physical prize.",
  },
  {
    q: "How quickly is the prize delivered?",
    a: "Cash is transferred within 48 hours of the draw. Cars and larger prizes are arranged directly with the winner, usually within two weeks.",
  },
];

export const competitionCategories = [
  "All",
  "Cars & Bikes",
  "Cash",
  "Luxury",
  "Tech",
  "Lifestyle",
] as const;

export type CompetitionSort = "ending-soon" | "newest" | "price-low" | "price-high";

export const competitionSorts: { value: CompetitionSort; label: string }[] = [
  { value: "ending-soon", label: "Ending soonest" },
  { value: "price-low", label: "Entry price: low to high" },
  { value: "price-high", label: "Entry price: high to low" },
  { value: "newest", label: "Newest" },
];
