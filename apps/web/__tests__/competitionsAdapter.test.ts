import { describe, expect, test } from "vitest";
import { adaptCompetition } from "@/lib/competitions/adapter";
import type { ApiCompetition, ApiCompetitionStatus } from "@/lib/competitions/types";

function apiCompetition(status: ApiCompetitionStatus): ApiCompetition {
  return {
    id: 1,
    slug: "bmw-m4",
    title: "BMW M4 Competition",
    short_description: "",
    description: [],
    category: "Cars",
    status,
    entry_price: 500,
    currency: "GBP",
    max_entries: 1000,
    max_entries_per_user: null,
    images: [],
    skill_question: null,
    starts_at: null,
    ends_at: null,
    draw_at: "2026-10-01T20:00:00Z",
    is_featured: false,
    prizes: [],
  };
}

describe("adaptCompetition — P8 lifecycle status mapping", () => {
  test("active maps to live", () => {
    expect(adaptCompetition(apiCompetition("active")).status).toBe("live");
  });

  test("sold_out maps to sold-out", () => {
    expect(adaptCompetition(apiCompetition("sold_out")).status).toBe("sold-out");
  });

  test("closed maps to closed — never 'drawn', a draw hasn't happened", () => {
    const competition = adaptCompetition(apiCompetition("closed"));
    expect(competition.status).toBe("closed");
    expect(competition.status).not.toBe("drawn");
  });

  test("draw_pending maps to draw-pending — never 'drawn'", () => {
    const competition = adaptCompetition(apiCompetition("draw_pending"));
    expect(competition.status).toBe("draw-pending");
    expect(competition.status).not.toBe("drawn");
  });

  test("drawn and completed both map to drawn", () => {
    expect(adaptCompetition(apiCompetition("drawn")).status).toBe("drawn");
    expect(adaptCompetition(apiCompetition("completed")).status).toBe("drawn");
  });

  test("badge reflects the real status, not a generic 'Live' fallback, once not purchasable", () => {
    expect(adaptCompetition(apiCompetition("sold_out")).badge).toBe("Sold out");
    expect(adaptCompetition(apiCompetition("closed")).badge).toBe("Closed");
    expect(adaptCompetition(apiCompetition("draw_pending")).badge).toBe("Draw pending");
    expect(adaptCompetition(apiCompetition("drawn")).badge).toBe("Drawn");
  });

  // -- P9: winning ticket number ------------------------------------------------------------

  test("winningTicketNumber is populated from api.draw once drawn", () => {
    const drawn = adaptCompetition({
      ...apiCompetition("drawn"),
      draw: { ticket_number: 4821, drawn_at: "2026-10-01T20:00:03Z" },
    });

    expect(drawn.winningTicketNumber).toBe(4821);
  });

  test("winningTicketNumber is undefined when no draw has happened", () => {
    expect(adaptCompetition(apiCompetition("active")).winningTicketNumber).toBeUndefined();
    expect(adaptCompetition(apiCompetition("closed")).winningTicketNumber).toBeUndefined();
    expect(adaptCompetition(apiCompetition("draw_pending")).winningTicketNumber).toBeUndefined();
  });
});
