import { afterEach, beforeEach, describe, expect, test, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import FeaturedCompetitions from "@/components/sections/FeaturedCompetitions";
import EndingSoon from "@/components/sections/EndingSoon";
import type { ApiCompetition } from "@/lib/competitions/types";

const listCompetitions = vi.fn();
vi.mock("@/lib/competitions/client", () => ({
  listCompetitions: (...args: unknown[]) => listCompetitions(...args),
}));

// Legacy mock titles from the removed homepage fixtures. None may ever render.
const MOCK_TITLES = ["Win a 718 Cayman GTS 4.0", "Porsche 911 Carrera", "Rolex Submariner Date"];

function apiCompetition(overrides: Partial<ApiCompetition> = {}): ApiCompetition {
  return {
    id: 10,
    slug: "bmw-m4",
    title: "BMW M4 Competition",
    short_description: "A track-ready M4.",
    description: null,
    category: "Cars & Bikes",
    status: "active",
    entry_price: 1000,
    currency: "GBP",
    max_entries: 5000,
    max_entries_per_user: null,
    images: ["https://images.unsplash.com/photo-1"],
    skill_question: null,
    starts_at: null,
    ends_at: null,
    draw_at: "2999-10-12T19:00:00Z",
    is_featured: true,
    prizes: [],
    ...overrides,
  };
}

beforeEach(() => {
  // The competition grid uses scroll-reveal; jsdom has no IntersectionObserver.
  vi.stubGlobal(
    "IntersectionObserver",
    class {
      observe() {}
      unobserve() {}
      disconnect() {}
    },
  );
});

afterEach(() => {
  vi.clearAllMocks();
  vi.unstubAllGlobals();
});

describe("FeaturedCompetitions (homepage)", () => {
  test("renders live competitions from the API with their real links", async () => {
    listCompetitions.mockResolvedValueOnce({
      data: [apiCompetition()],
      meta: { current_page: 1, last_page: 1, per_page: 4, total: 1 },
    });

    render(await FeaturedCompetitions());

    expect(await screen.findByRole("heading", { name: "BMW M4 Competition" })).toBeDefined();
    expect(screen.getByRole("link", { name: "BMW M4 Competition" }).getAttribute("href")).toBe(
      "/competitions/bmw-m4",
    );
  });

  test("asks the API for featured, open competitions soonest-draw first, capped at four", async () => {
    listCompetitions.mockResolvedValueOnce({ data: [], meta: { current_page: 1, last_page: 1, per_page: 4, total: 0 } });

    render(await FeaturedCompetitions());

    expect(listCompetitions).toHaveBeenCalledWith({
      featured: true,
      status: "active",
      sort: "ending_soon",
      per_page: 4,
    });
  });

  test("shows an empty state when no featured competition is live", async () => {
    listCompetitions.mockResolvedValueOnce({ data: [], meta: { current_page: 1, last_page: 1, per_page: 4, total: 0 } });

    render(await FeaturedCompetitions());

    expect(await screen.findByText("No live competitions right now")).toBeDefined();
  });

  test("shows an error state, never mock competitions, when the API fails", async () => {
    listCompetitions.mockRejectedValueOnce(new Error("network"));

    render(await FeaturedCompetitions());

    expect(await screen.findByText("Competitions are unavailable right now")).toBeDefined();
    for (const title of MOCK_TITLES) {
      expect(screen.queryByText(title)).toBeNull();
    }
  });
});

describe("EndingSoon (homepage)", () => {
  test("renders open competitions closing soonest from the API", async () => {
    listCompetitions.mockResolvedValueOnce({
      data: [apiCompetition({ slug: "soon", title: "Closing Soon Cash" })],
      meta: { current_page: 1, last_page: 1, per_page: 3, total: 1 },
    });

    render(await EndingSoon());

    expect(await screen.findByRole("heading", { name: "Closing Soon Cash" })).toBeDefined();
    expect(listCompetitions).toHaveBeenCalledWith({ status: "active", sort: "ending_soon", per_page: 3 });
  });

  test("shows an error state, never mock competitions, when the API fails", async () => {
    listCompetitions.mockRejectedValueOnce(new Error("network"));

    render(await EndingSoon());

    expect(await screen.findByText("Competitions are unavailable right now")).toBeDefined();
    for (const title of MOCK_TITLES) {
      expect(screen.queryByText(title)).toBeNull();
    }
  });
});
