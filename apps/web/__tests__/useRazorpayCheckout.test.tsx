import { afterEach, describe, expect, test, vi } from "vitest";
import { renderHook, waitFor, act } from "@testing-library/react";
import { useRazorpayCheckout } from "@/lib/payments/useRazorpayCheckout";
import { ApiValidationError } from "@/lib/api/http";

const initiatePayment = vi.fn();
const verifyPayment = vi.fn();
vi.mock("@/lib/payments/client", () => ({
  initiatePayment: (...args: unknown[]) => initiatePayment(...args),
  verifyPayment: (...args: unknown[]) => verifyPayment(...args),
}));

const openRazorpayCheckout = vi.fn();
const { FakeCheckoutDismissedError, FakeCheckoutFailedError } = vi.hoisted(() => {
  class FakeCheckoutDismissedError extends Error {}
  class FakeCheckoutFailedError extends Error {}
  return { FakeCheckoutDismissedError, FakeCheckoutFailedError };
});
vi.mock("@/lib/payments/checkout", () => ({
  openRazorpayCheckout: (...args: unknown[]) => openRazorpayCheckout(...args),
  CheckoutDismissedError: FakeCheckoutDismissedError,
  CheckoutFailedError: FakeCheckoutFailedError,
}));

const init = { key_id: "rzp_test", gateway_order_id: "order_x", amount: 500, currency: "GBP", reference: "RG-1", name: "RaffleGuru" };
const gatewayResponse = { razorpay_order_id: "order_x", razorpay_payment_id: "pay_x", razorpay_signature: "sig_x" };

describe("useRazorpayCheckout", () => {
  afterEach(() => vi.clearAllMocks());

  test("happy path: initiate -> checkout -> verify -> onPaid", async () => {
    initiatePayment.mockResolvedValueOnce({ data: init });
    openRazorpayCheckout.mockResolvedValueOnce(gatewayResponse);
    const order = { id: 1, reference: "RG-1", status: "paid" };
    verifyPayment.mockResolvedValueOnce({ data: order });
    const onPaid = vi.fn();

    const { result } = renderHook(() => useRazorpayCheckout(onPaid));

    await act(async () => {
      await result.current.pay("RG-1");
    });

    expect(initiatePayment).toHaveBeenCalledWith("RG-1");
    expect(openRazorpayCheckout).toHaveBeenCalledWith(init);
    expect(verifyPayment).toHaveBeenCalledWith(gatewayResponse);
    expect(onPaid).toHaveBeenCalledWith(order);
    expect(result.current.state).toEqual({ status: "idle" });
  });

  test("dismissed checkout sets a dismissed state and does not call verify", async () => {
    initiatePayment.mockResolvedValueOnce({ data: init });
    openRazorpayCheckout.mockRejectedValueOnce(new FakeCheckoutDismissedError());
    const onPaid = vi.fn();

    const { result } = renderHook(() => useRazorpayCheckout(onPaid));
    await act(async () => {
      await result.current.pay("RG-1");
    });

    expect(result.current.state).toEqual({ status: "dismissed" });
    expect(verifyPayment).not.toHaveBeenCalled();
    expect(onPaid).not.toHaveBeenCalled();
  });

  test("gateway-reported failure sets an error state without calling verify", async () => {
    initiatePayment.mockResolvedValueOnce({ data: init });
    openRazorpayCheckout.mockRejectedValueOnce(new FakeCheckoutFailedError("Card declined."));
    const onPaid = vi.fn();

    const { result } = renderHook(() => useRazorpayCheckout(onPaid));
    await act(async () => {
      await result.current.pay("RG-1");
    });

    expect(result.current.state).toEqual({ status: "error", message: "Card declined." });
    expect(verifyPayment).not.toHaveBeenCalled();
  });

  test("a server verification failure surfaces the API's message and never calls onPaid", async () => {
    initiatePayment.mockResolvedValueOnce({ data: init });
    openRazorpayCheckout.mockResolvedValueOnce(gatewayResponse);
    verifyPayment.mockRejectedValueOnce(
      new ApiValidationError("Validation failed.", { payment: ["Payment could not be verified."] }),
    );
    const onPaid = vi.fn();

    const { result } = renderHook(() => useRazorpayCheckout(onPaid));
    await act(async () => {
      await result.current.pay("RG-1");
    });

    expect(result.current.state).toEqual({ status: "error", message: "Payment could not be verified." });
    expect(onPaid).not.toHaveBeenCalled();
  });

  test("enters the processing state immediately while initiation is in flight", async () => {
    let resolveInitiate!: (v: unknown) => void;
    initiatePayment.mockReturnValueOnce(new Promise((resolve) => (resolveInitiate = resolve)));
    const onPaid = vi.fn();

    const { result } = renderHook(() => useRazorpayCheckout(onPaid));

    act(() => {
      void result.current.pay("RG-1");
    });
    await waitFor(() => expect(result.current.state).toEqual({ status: "processing" }));

    // Let the in-flight call settle so the test doesn't leak a dangling
    // promise/unhandled rejection into later tests.
    openRazorpayCheckout.mockResolvedValueOnce(gatewayResponse);
    verifyPayment.mockResolvedValueOnce({ data: { id: 1, reference: "RG-1", status: "paid" } });
    await act(async () => {
      resolveInitiate({ data: init });
    });
  });
});
