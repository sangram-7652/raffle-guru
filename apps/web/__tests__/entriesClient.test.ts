import { afterEach, describe, expect, test, vi } from "vitest";
import { getEntry, listEntries } from "@/lib/entries/client";
import { ApiError } from "@/lib/api/http";

function jsonResponse(body: unknown, status = 200) {
  return {
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(body),
  } as Response;
}

const entry = {
  id: 1,
  competition: { id: 10, slug: "bmw-m4", title: "BMW M4" },
  order_reference: "RG-482915",
  quantity: 3,
  ticket_numbers: [1, 2, 3],
  allocated_at: "2026-09-13T12:00:00Z",
};

describe("entries client", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  test("listEntries requests the given page", async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      jsonResponse({ data: [entry], meta: { current_page: 1, last_page: 1, per_page: 10, total: 1 } }),
    );
    vi.stubGlobal("fetch", fetchMock);

    const result = await listEntries(1);

    expect(fetchMock.mock.calls[0][0]).toContain("/v1/entries?page=1");
    expect(result.data).toHaveLength(1);
    expect(result.data[0].ticket_numbers).toEqual([1, 2, 3]);
  });

  test("getEntry returns the entry on success", async () => {
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue(jsonResponse({ data: entry })));

    const result = await getEntry(1);

    expect(result?.order_reference).toBe("RG-482915");
  });

  test("getEntry returns null on 404 (not found or not owned by this user)", async () => {
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue(jsonResponse({ message: "Not found" }, 404)));

    const result = await getEntry(999);

    expect(result).toBeNull();
  });

  test("getEntry re-throws non-404 errors", async () => {
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue(jsonResponse({ message: "Server error" }, 500)));

    await expect(getEntry(1)).rejects.toBeInstanceOf(ApiError);
  });
});
