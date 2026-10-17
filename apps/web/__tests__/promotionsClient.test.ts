import { afterEach, describe, expect, test, vi } from "vitest";
import { claimPromotion, getPromotion, getPromotionClaim } from "@/lib/promotions/client";
import { ApiValidationError } from "@/lib/api/http";

function jsonResponse(body: unknown, status = 200) {
  return {
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(body),
  } as Response;
}

describe("promotions client", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  test("getPromotion issues a plain GET and returns the open state", async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      jsonResponse({ data: { slug: "welcome-ticket", name: "Welcome", is_open: true } }),
    );
    vi.stubGlobal("fetch", fetchMock);

    const result = await getPromotion("welcome-ticket");

    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(fetchMock.mock.calls[0][0]).toContain("/v1/promotions/welcome-ticket");
    expect(result?.is_open).toBe(true);
  });

  test("getPromotion returns null when no promotion is configured", async () => {
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue(jsonResponse({ data: null })));

    const result = await getPromotion("welcome-ticket");

    expect(result).toBeNull();
  });

  test("getPromotionClaim returns null when the user has not claimed", async () => {
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue(jsonResponse({ data: null })));

    const result = await getPromotionClaim("welcome-ticket");

    expect(result).toBeNull();
  });

  test("claimPromotion fetches a CSRF cookie first, then posts", async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      jsonResponse({ data: { status: "fulfilled", failure_reason: null, entry_id: 42 } }),
    );
    vi.stubGlobal("fetch", fetchMock);

    const result = await claimPromotion("welcome-ticket");

    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(fetchMock.mock.calls[0][0]).toContain("/sanctum/csrf-cookie");
    const [url, init] = fetchMock.mock.calls[1];
    expect(url).toContain("/v1/account/promotions/welcome-ticket/claim");
    expect(init.method).toBe("POST");
    expect(result.status).toBe("fulfilled");
    expect(result.entry_id).toBe(42);
  });

  test("an ineligible claim surfaces as an ApiValidationError under the promotion field", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue(
        jsonResponse(
          { message: "Validation failed.", errors: { promotion: ["This offer has reached its claim limit."] } },
          422,
        ),
      ),
    );

    await expect(claimPromotion("welcome-ticket")).rejects.toBeInstanceOf(ApiValidationError);
  });
});
