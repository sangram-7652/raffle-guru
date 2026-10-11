/**
 * Pure derivation of the account overview from real API data. No fetching
 * and no React here — the overview hook gathers the inputs, this decides
 * what they mean. Nothing is inferred beyond what the API returned:
 * ticket counts are the allocated `ticket_numbers`, draw dates are the
 * competition's own `draw_at` (or null when unknown).
 */
import type { ApiCompetition, ApiCompetitionStatus } from "@/lib/competitions/types";
import type { ApiEntry } from "@/lib/entries/types";
import type { ApiOrderStatus } from "@/lib/orders/types";

export type AccountCompetition = {
  id: number;
  slug: string;
  title: string;
  status: ApiCompetitionStatus;
  /** Tickets this customer holds in this competition, across all orders. */
  ticketCount: number;
  /** null when the competition API could not be read or has no draw time. */
  drawAt: string | null;
  image: string | null;
};

export type AccountSummary = {
  entryCount: number;
  ticketCount: number;
  /** Every competition the customer holds tickets in that hasn't been drawn. */
  activeCompetitions: AccountCompetition[];
  /** Active competitions whose draw hasn't passed (or has no known time). */
  upcomingDraws: AccountCompetition[];
};

const FINISHED: ReadonlySet<ApiCompetitionStatus> = new Set(["drawn", "completed"]);

/** Ascending by draw time, unknown draw times last. */
function byDrawAt(a: AccountCompetition, b: AccountCompetition): number {
  if (a.drawAt === b.drawAt) return a.title.localeCompare(b.title);
  if (a.drawAt === null) return 1;
  if (b.drawAt === null) return -1;
  return Date.parse(a.drawAt) - Date.parse(b.drawAt);
}

export function buildAccountSummary(
  entries: ApiEntry[],
  competitions: ReadonlyMap<string, ApiCompetition>,
  now: number,
): AccountSummary {
  const grouped = new Map<number, AccountCompetition>();

  for (const entry of entries) {
    const existing = grouped.get(entry.competition.id);
    if (existing) {
      existing.ticketCount += entry.ticket_numbers.length;
      continue;
    }
    const details = competitions.get(entry.competition.slug);
    grouped.set(entry.competition.id, {
      id: entry.competition.id,
      slug: entry.competition.slug,
      title: entry.competition.title,
      status: entry.competition.status,
      ticketCount: entry.ticket_numbers.length,
      drawAt: details?.draw_at ?? null,
      image: details?.images?.[0] ?? null,
    });
  }

  const active = [...grouped.values()]
    .filter((c) => !FINISHED.has(c.status))
    .sort(byDrawAt);

  const upcoming = active.filter((c) => c.drawAt === null || Date.parse(c.drawAt) > now);

  return {
    entryCount: entries.length,
    ticketCount: entries.reduce((sum, e) => sum + e.ticket_numbers.length, 0),
    activeCompetitions: active,
    upcomingDraws: upcoming,
  };
}

const ORDER_STATUS_LABEL: Record<ApiOrderStatus, string> = {
  pending: "Payment pending",
  payment_pending: "Payment pending",
  paid: "Paid",
  processing: "Processing",
  completed: "Completed",
  cancelled: "Cancelled",
  failed: "Failed",
  refunded: "Refunded",
};

export function orderStatusLabel(status: ApiOrderStatus): string {
  return ORDER_STATUS_LABEL[status];
}

/** Customer-facing label for a competition's lifecycle status. */
export function competitionStatusLabel(status: ApiCompetitionStatus): string {
  switch (status) {
    case "active":
    case "scheduled":
      return "Open";
    case "sold_out":
      return "Sold out";
    case "closed":
    case "draw_in_progress":
      return "Closed";
    case "draw_pending":
      return "Draw pending";
    case "drawn":
    case "completed":
      return "Drawn";
    case "draft":
      return "Draft";
  }
}
