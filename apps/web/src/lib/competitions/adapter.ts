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
 * - `status` maps the backend's 9-value lifecycle (P8) onto the mock's
 *   union (never "draft"/"scheduled" here — the public API never
 *   returns those). "closed" and "draw-pending" are kept distinct from
 *   "drawn" — see @/lib/competitionAvailability.ts — so the UI never
 *   claims a draw happened before it actually has.
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
import { competitionAvailability } from "@/lib/competitionAvailability";
import { drawLabelShort, gbp } from "@/lib/format";
import type { ApiCompetition, ApiCompetitionStatus } from "./types";

export function toDisplayStatus(status: ApiCompetitionStatus): Competition["status"] {
  switch (status) {
    case "sold_out":
      return "sold-out";
    case "closed":
      return "closed";
    case "draw_pending":
      return "draw-pending";
    case "drawn":
    case "completed":
      return "drawn";
    // draw_in_progress is P9's — this app never produces it yet, but if
    // it ever appears, treat it the same as "closed" (not purchasable,
    // not yet drawn) rather than falsely claiming "drawn".
    case "draw_in_progress":
      return "closed";
    case "active":
    case "scheduled":
    default:
      return "live";
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
    badge: (() => {
      const availability = competitionAvailability(status);
      if (!availability.purchasable) return availability.label;
      return api.is_featured ? "Featured" : "Live";
    })(),
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
    winningTicketNumber: api.draw?.ticket_number,
    question: {
      prompt: api.skill_question?.prompt ?? "",
      options: api.skill_question?.options ?? [],
      answerIndex: -1,
    },
    faqs: standardFaqs,
  };
}
