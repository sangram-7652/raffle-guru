import { afterEach, describe, expect, test, vi } from "vitest";
import { createOrder, getOrder, listOrders } from "@/lib/orders/client";
import { ApiError } from "@/lib/api/http";

function jsonResponse(body: unknown, status = 200) {
  return {
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(body),
  } as Response;
}

const order = {
  id: 1,
  reference: "RG-482915",
  status: "pending",
  currency: "GBP",
  subtotal: 500,
  total: 500,
  items: [],
  created_at: "2026-09-13T12:00:00Z",
};

describe("orders client", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  test("createOrder fetches a CSRF cookie then POSTs with no body", async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ data: order }));
    vi.stubGlobal("fetch", fetchMock);

    const result = await createOrder();

    expect(fetchMock.mock.calls[0][0]).toContain("/sanctum/csrf-cookie");
    const [url, init] = fetchMock.mock.calls[1];
    expect(url).toContain("/v1/orders");
    expect(init.method).toBe("POST");
    expect(result.data.status).toBe("pending");
  });

  test("listOrders requests the given page", async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      jsonResponse({ data: [order], meta: { current_page: 2, last_page: 2, per_page: 10, total: 11 } }),
    );
    vi.stubGlobal("fetch", fetchMock);

    const result = await listOrders(2);

    expect(fetchMock.mock.calls[0][0]).toContain("/v1/orders?page=2");
    expect(result.data).toHaveLength(1);
  });

  test("getOrder returns the order on success", async () => {
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue(jsonResponse({ data: order })));

    const result = await getOrder("RG-482915");

    expect(result?.reference).toBe("RG-482915");
  });

  test("getOrder returns null on 404 (not found or not owned by this user)", async () => {
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue(jsonResponse({ message: "Not found" }, 404)));

    const result = await getOrder("RG-000000");

    expect(result).toBeNull();
  });

  test("getOrder re-throws non-404 errors", async () => {
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue(jsonResponse({ message: "Server error" }, 500)));

    await expect(getOrder("RG-482915")).rejects.toBeInstanceOf(ApiError);
  });
});
