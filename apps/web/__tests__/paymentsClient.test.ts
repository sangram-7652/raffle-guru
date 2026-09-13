import { afterEach, describe, expect, test, vi } from "vitest";
import { initiatePayment, verifyPayment } from "@/lib/payments/client";

function jsonResponse(body: unknown, status = 200) {
  return {
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(body),
  } as Response;
}

describe("payments client", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  test("initiatePayment fetches a CSRF cookie then POSTs to the order's payment endpoint", async () => {
    const data = {
      key_id: "rzp_test_x",
      gateway_order_id: "order_x",
      amount: 500,
      currency: "GBP",
      reference: "RG-482915",
      name: "RaffleGuru",
    };
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ data }));
    vi.stubGlobal("fetch", fetchMock);

    const result = await initiatePayment("RG-482915");

    expect(fetchMock.mock.calls[0][0]).toContain("/sanctum/csrf-cookie");
    const [url, init] = fetchMock.mock.calls[1];
    expect(url).toContain("/v1/orders/RG-482915/payment");
    expect(init.method).toBe("POST");
    expect(result.data.key_id).toBe("rzp_test_x");
  });

  test("verifyPayment posts the gateway's response fields", async () => {
    const order = { id: 1, reference: "RG-482915", status: "paid" };
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ data: order }));
    vi.stubGlobal("fetch", fetchMock);

    const payload = {
      razorpay_order_id: "order_x",
      razorpay_payment_id: "pay_x",
      razorpay_signature: "sig_x",
    };
    const result = await verifyPayment(payload);

    const [url, init] = fetchMock.mock.calls[1];
    expect(url).toContain("/v1/payments/verify");
    expect(init.method).toBe("POST");
    expect(JSON.parse(init.body)).toEqual(payload);
    expect(result.data.status).toBe("paid");
  });
});
