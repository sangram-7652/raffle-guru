import { describe, expect, test } from "vitest";
import { buildAccountSummary, competitionStatusLabel, orderStatusLabel } from "@/lib/account/summary";
import type { ApiCompetition } from "@/lib/competitions/types";
import type { ApiEntry } from "@/lib/entries/types";

const NOW = Date.parse("2026-10-01T12:00:00Z");

type EntryOverrides = Partial<Omit<ApiEntry, "competition">> & {
  competition?: Partial<ApiEntry["competition"]>;
};

function entry(overrides: EntryOverrides = {}): ApiEntry {
  const { competition, ...rest } = overrides;
  return {
    id: 1,
    competition: { id: 10, slug: "bmw-m4", title: "BMW M4", status: "active", ...competition },
    order_reference: "RG-1",
    quantity: 2,
    ticket_numbers: [4, 9],
    allocated_at: "2026-09-01T10:00:00Z",
    ...rest,
  };
}

function competition(overrides: Partial<ApiCompetition> = {}): ApiCompetition {
  return {
    id: 10,
    slug: "bmw-m4",
    title: "BMW M4",
    short_description: "",
    description: null,
    category: "cars",
    status: "active",
    entry_price: 200,
    currency: "GBP",
    max_entries: 1000,
    max_entries_per_user: null,
    images: ["https://images.unsplash.com/photo-1"],
    skill_question: null,
    starts_at: null,
    ends_at: null,
    draw_at: "2026-10-10T19:00:00Z",
    is_featured: false,
    prizes: [],
    ...overrides,
  };
}

describe("buildAccountSummary", () => {
  test("no entries gives zero counts and empty lists — no invented data", () => {
    const summary = buildAccountSummary([], new Map(), NOW);

    expect(summary).toEqual({
      entryCount: 0,
      ticketCount: 0,
      activeCompetitions: [],
      upcomingDraws: [],
    });
  });

  test("counts entries and tickets across all entries", () => {
    const summary = buildAccountSummary(
      [entry({ id: 1 }), entry({ id: 2, ticket_numbers: [11] })],
      new Map([["bmw-m4", competition()]]),
      NOW,
    );

    expect(summary.entryCount).toBe(2);
    expect(summary.ticketCount).toBe(3);
  });

  test("groups multiple orders for one competition into a single active card", () => {
    const summary = buildAccountSummary(
      [entry({ id: 1 }), entry({ id: 2, order_reference: "RG-2", ticket_numbers: [20] })],
      new Map([["bmw-m4", competition()]]),
      NOW,
    );

    expect(summary.activeCompetitions).toHaveLength(1);
    expect(summary.activeCompetitions[0].ticketCount).toBe(3);
  });

  test("takes the draw time and image from the competition API", () => {
    const [active] = buildAccountSummary(
      [entry()],
      new Map([["bmw-m4", competition()]]),
      NOW,
    ).activeCompetitions;

    expect(active.drawAt).toBe("2026-10-10T19:00:00Z");
    expect(active.image).toBe("https://images.unsplash.com/photo-1");
  });

  test("leaves draw time and image null when the competition details are missing", () => {
    const [active] = buildAccountSummary([entry()], new Map(), NOW).activeCompetitions;

    expect(active.drawAt).toBeNull();
    expect(active.image).toBeNull();
  });

  test("drawn and completed competitions are not active and not upcoming", () => {
    const summary = buildAccountSummary(
      [
        entry({ id: 1, competition: { id: 1, slug: "a", title: "A", status: "drawn" } }),
        entry({ id: 2, competition: { id: 2, slug: "b", title: "B", status: "completed" } }),
      ],
      new Map(),
      NOW,
    );

    expect(summary.activeCompetitions).toEqual([]);
    expect(summary.upcomingDraws).toEqual([]);
  });

  test("a draw that has already passed is active but not upcoming", () => {
    const summary = buildAccountSummary(
      [entry({ competition: { status: "draw_pending" } })],
      new Map([["bmw-m4", competition({ draw_at: "2026-09-01T19:00:00Z" })]]),
      NOW,
    );

    expect(summary.activeCompetitions).toHaveLength(1);
    expect(summary.upcomingDraws).toEqual([]);
  });

  test("a competition with no known draw time is still upcoming, not given a made-up date", () => {
    const summary = buildAccountSummary(
      [entry()],
      new Map([["bmw-m4", competition({ draw_at: null })]]),
      NOW,
    );

    expect(summary.upcomingDraws).toHaveLength(1);
    expect(summary.upcomingDraws[0].drawAt).toBeNull();
  });

  test("orders active competitions and upcoming draws soonest first, unknown dates last", () => {
    const summary = buildAccountSummary(
      [
        entry({ id: 1, competition: { id: 1, slug: "late", title: "Late", status: "active" } }),
        entry({ id: 2, competition: { id: 2, slug: "none", title: "None", status: "active" } }),
        entry({ id: 3, competition: { id: 3, slug: "soon", title: "Soon", status: "active" } }),
      ],
      new Map([
        ["late", competition({ id: 1, slug: "late", draw_at: "2026-12-01T19:00:00Z" })],
        ["none", competition({ id: 2, slug: "none", draw_at: null })],
        ["soon", competition({ id: 3, slug: "soon", draw_at: "2026-10-05T19:00:00Z" })],
      ]),
      NOW,
    );

    expect(summary.upcomingDraws.map((c) => c.slug)).toEqual(["soon", "late", "none"]);
    expect(summary.activeCompetitions.map((c) => c.slug)).toEqual(["soon", "late", "none"]);
  });
});

describe("status labels", () => {
  test("competition statuses map to customer-facing labels", () => {
    expect(competitionStatusLabel("active")).toBe("Open");
    expect(competitionStatusLabel("sold_out")).toBe("Sold out");
    expect(competitionStatusLabel("closed")).toBe("Closed");
    expect(competitionStatusLabel("draw_pending")).toBe("Draw pending");
    expect(competitionStatusLabel("drawn")).toBe("Drawn");
  });

  test("order statuses map to the same labels the old account page used", () => {
    expect(orderStatusLabel("pending")).toBe("Payment pending");
    expect(orderStatusLabel("paid")).toBe("Paid");
  });
});
