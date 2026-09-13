import { afterEach, describe, expect, test, vi } from "vitest";
import { addCartItem, getCart, removeCartItem, updateCartItem } from "@/lib/cart/client";

function jsonResponse(body: unknown, status = 200) {
  return {
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(body),
  } as Response;
}

const cart = { id: 1, currency: "GBP", items: [], subtotal: 0, count: 0 };

describe("cart client", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  test("getCart issues a plain GET with no CSRF handshake", async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ data: cart }));
    vi.stubGlobal("fetch", fetchMock);

    const result = await getCart();

    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(fetchMock.mock.calls[0][0]).toContain("/v1/cart");
    expect(result.data).toEqual(cart);
  });

  test("addCartItem fetches a CSRF cookie first, then posts competition_id/quantity", async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ data: cart }));
    vi.stubGlobal("fetch", fetchMock);

    await addCartItem(7, 3);

    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(fetchMock.mock.calls[0][0]).toContain("/sanctum/csrf-cookie");
    const [url, init] = fetchMock.mock.calls[1];
    expect(url).toContain("/v1/cart/items");
    expect(init.method).toBe("POST");
    expect(JSON.parse(init.body)).toEqual({ competition_id: 7, quantity: 3 });
  });

  test("updateCartItem sends a PATCH with the new quantity", async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ data: cart }));
    vi.stubGlobal("fetch", fetchMock);

    await updateCartItem(9, 5);

    const [url, init] = fetchMock.mock.calls[1];
    expect(url).toContain("/v1/cart/items/9");
    expect(init.method).toBe("PATCH");
    expect(JSON.parse(init.body)).toEqual({ quantity: 5 });
  });

  test("removeCartItem sends a DELETE", async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ data: cart }));
    vi.stubGlobal("fetch", fetchMock);

    await removeCartItem(9);

    const [url, init] = fetchMock.mock.calls[1];
    expect(url).toContain("/v1/cart/items/9");
    expect(init.method).toBe("DELETE");
  });
});
