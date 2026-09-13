/**
 * Maps a real ApiCompetition onto the existing mock `Competition` view
 * model (@/data/competitions) so CompetitionCard/CompetitionGrid/
 * CompetitionFacts/CompetitionInfo/EntrySelector/etc. keep working
 * completely unchanged — no component redesign, just a different data
 * source feeding the same shape.
 *
 * Known, documented limitations of this mapping (see
 * docs/COMPETITIONS_API.md "Frontend integration"):
 * - `soldPct` is always 0 — there's no ticket engine yet (P7) to compute
 *   a real sold percentage from.
 * - `status` collapses the backend's 9-value lifecycle onto the mock's
 *   4-value union (only ever "live"/"sold-out"/"drawn" here — the public
 *   API never returns "draft"/"scheduled" in the first place).
 * - `question.answerIndex` is always -1 (never a real option index): the
 *   API never sends the answer, so CompetitionQuestion can no longer
 *   show "correct" — the honest behavior once the answer truly isn't
 *   known client-side.
 * - `rules`/`faqs` reuse the same generic platform copy the mock data
 *   always used (see docs/SCHEMA_DESIGN_P2.md — this was never
 *   per-competition data even in the mock).
 */
import {
  standardFaqs,
  standardRules,
  type Competition,
} from "@/data/competitions";
import { drawLabelShort, gbp } from "@/lib/format";
import type { ApiCompetition, ApiCompetitionStatus } from "./types";

function toDisplayStatus(status: ApiCompetitionStatus): Competition["status"] {
  switch (status) {
    case "sold_out":
      return "sold-out";
    case "active":
    case "scheduled":
      return "live";
    default:
      return "drawn";
  }
}

export function adaptCompetition(api: ApiCompetition): Competition {
  const primaryPrize = api.prizes[0];
  const status = toDisplayStatus(api.status);
  const cashAlternativeValue = primaryPrize?.cash_alternative_value
    ? primaryPrize.cash_alternative_value / 100
    : undefined;

  return {
    id: String(api.id),
    slug: api.slug,
    title: api.title,
    blurb: api.short_description,
    image: api.images?.[0] ?? "",
    badge:
      status === "sold-out"
        ? "Sold Out"
        : status === "drawn"
          ? "Drawn"
          : api.is_featured
            ? "Featured"
            : "Live",
    category: api.category,
    entryPrice: api.entry_price / 100,
    cashAlternative: cashAlternativeValue ? `or ${gbp(cashAlternativeValue)} cash` : undefined,
    soldPct: 0,
    drawLabel: api.draw_at ? drawLabelShort(api.draw_at) : "TBC",
    prize: primaryPrize?.title ?? api.title,
    prizeValue: (primaryPrize?.value ?? 0) / 100,
    cashAlternativeValue,
    images: api.images ?? [],
    totalEntries: api.max_entries,
    drawDate: api.draw_at ?? new Date().toISOString(),
    status,
    featured: api.is_featured,
    description: api.description ?? [],
    prizeDetails: primaryPrize?.description ?? [],
    rules: standardRules,
    question: {
      prompt: api.skill_question?.prompt ?? "",
      options: api.skill_question?.options ?? [],
      answerIndex: -1,
    },
    faqs: standardFaqs,
  };
}
