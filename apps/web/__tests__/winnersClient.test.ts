import { afterEach, describe, expect, test, vi } from "vitest";
import { getCurrentWinner, getWinnerClaim, submitWinnerClaim } from "@/lib/winners/client";

function jsonResponse(body: unknown, status = 200) {
  return {
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(body),
  } as Response;
}

const winner = {
  id: 1,
  status: "contacted",
  competition: { id: 10, slug: "bmw-m4", title: "BMW M4" },
  winning_ticket_number: 42,
  prize: { title: "BMW M4", value: 5000000, currency: "GBP" },
  contacted_at: "2026-09-14T12:00:00Z",
  verified_at: null,
  claim_approved_at: null,
  delivery_pending_at: null,
  delivered_at: null,
  completed_at: null,
  claim: null,
  delivery: null,
  created_at: "2026-09-14T10:00:00Z",
};

describe("winners client", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  test("getCurrentWinner returns the winner on success", async () => {
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue(jsonResponse({ data: winner })));

    const result = await getCurrentWinner();

    expect(result?.winning_ticket_number).toBe(42);
  });

  test("getCurrentWinner returns null when the user has never won", async () => {
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue(jsonResponse({ data: null })));

    const result = await getCurrentWinner();

    expect(result).toBeNull();
  });

  test("getWinnerClaim requests the winner-scoped claim endpoint", async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ data: null }));
    vi.stubGlobal("fetch", fetchMock);

    await getWinnerClaim(1);

    expect(fetchMock.mock.calls[0][0]).toContain("/v1/account/winners/1/claim");
  });

  test("submitWinnerClaim posts only notes — never an identity field", async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      jsonResponse({ data: { id: 5, status: "submitted", submitted_at: null, verified_at: null, rejected_at: null, rejection_reason: null, notes: "hi" } }, 201),
    );
    vi.stubGlobal("fetch", fetchMock);

    await submitWinnerClaim(1, "hi");

    // calls[0] is the Sanctum CSRF-cookie handshake fetch; the actual
    // POST is calls[1] — see @/lib/api/http's ensureCsrfCookie().
    const [url, options] = fetchMock.mock.calls[1];
    expect(url).toContain("/v1/account/winners/1/claim");
    expect(options.method).toBe("POST");
    expect(JSON.parse(options.body)).toEqual({ notes: "hi" });
  });
});
