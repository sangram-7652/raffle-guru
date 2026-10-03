import { afterEach, describe, expect, test, vi } from "vitest";
import { loadHomepageCompetitions, featuredSectionParams } from "@/lib/competitions/homepage";

const listCompetitions = vi.fn();
vi.mock("@/lib/competitions/client", () => ({
  listCompetitions: (...args: unknown[]) => listCompetitions(...args),
}));

afterEach(() => {
  vi.useRealTimers();
  vi.clearAllMocks();
});

describe("loadHomepageCompetitions", () => {
  test("a hung API becomes an error state after the timeout, so the homepage still renders", async () => {
    vi.useFakeTimers();
    listCompetitions.mockReturnValue(new Promise(() => {}));

    const pending = loadHomepageCompetitions(featuredSectionParams);
    await vi.advanceTimersByTimeAsync(8000);

    await expect(pending).resolves.toEqual({ state: "error" });
  });

  test("an API failure is an error state, never an empty or mock result", async () => {
    listCompetitions.mockRejectedValueOnce(new Error("502"));

    await expect(loadHomepageCompetitions(featuredSectionParams)).resolves.toEqual({ state: "error" });
  });

  test("a successful empty response is an empty state", async () => {
    listCompetitions.mockResolvedValueOnce({ data: [], meta: { current_page: 1, last_page: 1, per_page: 4, total: 0 } });

    await expect(loadHomepageCompetitions(featuredSectionParams)).resolves.toEqual({ state: "empty" });
  });
});
