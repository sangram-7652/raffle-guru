/**
 * Single place that decides "can this competition still be entered, and
 * what should the UI say if not" — used by EntrySelector and
 * StickyEnterBar so the two never drift. Server status (P8) is
 * authoritative; this never invents a status client-side (no
 * countdown-driven guessing) — it only reads what the adapter already
 * mapped from the real API response. See
 * docs/COMPETITION_LIFECYCLE_P8.md.
 */
import type { CompetitionStatus } from "@/data/competitions";

export type CompetitionAvailability = {
  purchasable: boolean;
  /** Short label for a disabled button / status chip. */
  label: string;
  /** Longer explanation shown in the entry panel. */
  description: string;
};

export function competitionAvailability(status: CompetitionStatus): CompetitionAvailability {
  switch (status) {
    case "live":
      return { purchasable: true, label: "Enter now", description: "" };
    case "sold-out":
      return {
        purchasable: false,
        label: "Sold out",
        description: "Every ticket has been claimed.",
      };
    case "closed":
      return {
        purchasable: false,
        label: "Closed",
        description: "Entries have closed for this competition.",
      };
    case "draw-pending":
      return {
        purchasable: false,
        label: "Draw pending",
        description: "Entries have closed — the draw hasn't happened yet.",
      };
    case "drawn":
      return {
        purchasable: false,
        label: "Drawn",
        description: "This competition has already been drawn.",
      };
    case "ending-soon":
      return { purchasable: true, label: "Enter now", description: "" };
  }
}
