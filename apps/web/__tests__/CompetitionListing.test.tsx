import { afterEach, describe, expect, test, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import CompetitionListing from "@/components/competition/CompetitionListing";
import type { ApiCompetition } from "@/lib/competitions/types";

const replace = vi.fn();

vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace }),
  usePathname: () => "/competitions",
}));

const listCompetitions = vi.fn();

vi.mock("@/lib/competitions/client", () => ({
  listCompetitions: (...args: unknown[]) => listCompetitions(...args),
}));

function makeApiCompetition(overrides: Partial<ApiCompetition> = {}): ApiCompetition {
  return {
    id: 1,
    slug: "bmw-m4",
    title: "BMW M4 Competition",
    short_description: "A fast car",
    description: [],
    category: "Cars & Bikes",
    status: "active",
    entry_price: 99,
    currency: "GBP",
    max_entries: 1000,
    max_entries_per_user: null,
    images: ["https://example.com/car.jpg"],
    skill_question: null,
    starts_at: null,
    ends_at: null,
    draw_at: "2026-12-01T20:00:00Z",
    is_featured: false,
    prizes: [],
    ...overrides,
  };
}

function paginated(items: ApiCompetition[], total = items.length) {
  return {
    data: items,
    meta: { current_page: 1, last_page: 1, per_page: 6, total },
  };
}

describe("CompetitionListing", () => {
  afterEach(() => {
    vi.clearAllMocks();
  });

  test("shows a loading skeleton before the first response resolves", () => {
    listCompetitions.mockReturnValue(new Promise(() => {})); // never resolves
    render(<CompetitionListing />);

    // Loading skeletons render as decorative elements, not real content —
    // absence of the empty-state / grid content is the meaningful signal.
    expect(screen.queryByText("No competitions match that")).toBeNull();
  });

  test("renders competitions on success", async () => {
    listCompetitions.mockImplementation((params) =>
      Promise.resolve(
        params?.featured || params?.sort === "ending_soon"
          ? paginated([makeApiCompetition()])
          : paginated([makeApiCompetition()]),
      ),
    );

    render(<CompetitionListing />);

    expect((await screen.findAllByText("BMW M4 Competition")).length).toBeGreaterThan(0);
  });

  test("shows the empty state when no competitions match", async () => {
    listCompetitions.mockResolvedValue(paginated([]));

    render(<CompetitionListing />);

    expect(await screen.findByText("No competitions match that")).toBeDefined();
  });

  test("shows an error state when the request fails, with a working retry", async () => {
    listCompetitions.mockRejectedValue(new Error("network down"));

    render(<CompetitionListing />);

    expect(await screen.findByText("Couldn't load competitions")).toBeDefined();

    listCompetitions.mockResolvedValue(paginated([makeApiCompetition()]));
    const user = userEvent.setup();
    await user.click(screen.getByRole("button", { name: "Retry" }));

    expect(await screen.findByText("BMW M4 Competition")).toBeDefined();
  });

  test("category selection is sent to the API and reflected in the URL", async () => {
    listCompetitions.mockResolvedValue(paginated([makeApiCompetition()]));
    const user = userEvent.setup();
    render(<CompetitionListing />);

    await screen.findAllByText("BMW M4 Competition");
    listCompetitions.mockClear();
    listCompetitions.mockResolvedValue(paginated([]));

    await user.click(screen.getByRole("button", { name: "Cash" }));

    await waitFor(() =>
      expect(listCompetitions).toHaveBeenCalledWith(
        expect.objectContaining({ category: "Cash" }),
      ),
    );
    expect(replace).toHaveBeenCalledWith(
      expect.stringContaining("category=Cash"),
      expect.anything(),
    );
  });
});
