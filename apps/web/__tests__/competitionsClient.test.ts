import { afterEach, describe, expect, test, vi } from "vitest";
import { getCompetition, listCompetitions } from "@/lib/competitions/client";
import { CompetitionApiError } from "@/lib/competitions/types";

function jsonResponse(body: unknown, status = 200) {
  return {
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(body),
  } as Response;
}

describe("competitions client", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  test("listCompetitions builds query params and returns the paginated response", async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      jsonResponse({ data: [], meta: { current_page: 1, last_page: 1, per_page: 12, total: 0 } }),
    );
    vi.stubGlobal("fetch", fetchMock);

    await listCompetitions({ search: "bmw", category: "Cars", featured: true, page: 2 });

    const calledUrl = fetchMock.mock.calls[0][0] as string;
    expect(calledUrl).toContain("/v1/competitions?");
    expect(calledUrl).toContain("search=bmw");
    expect(calledUrl).toContain("category=Cars");
    expect(calledUrl).toContain("featured=1");
    expect(calledUrl).toContain("page=2");
  });

  test("listCompetitions throws CompetitionApiError on a non-2xx response", async () => {
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue(jsonResponse({ message: "Nope" }, 422)));

    await expect(listCompetitions()).rejects.toBeInstanceOf(CompetitionApiError);
  });

  test("getCompetition returns the competition on success", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue(jsonResponse({ data: { slug: "bmw-m4", title: "BMW M4" } })),
    );

    const result = await getCompetition("bmw-m4");

    expect(result?.title).toBe("BMW M4");
  });

  test("getCompetition returns null on 404 instead of throwing", async () => {
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue(jsonResponse({ message: "Not found" }, 404)));

    const result = await getCompetition("does-not-exist");

    expect(result).toBeNull();
  });

  test("getCompetition re-throws non-404 errors", async () => {
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue(jsonResponse({ message: "Server error" }, 500)));

    await expect(getCompetition("bmw-m4")).rejects.toBeInstanceOf(CompetitionApiError);
  });
});
