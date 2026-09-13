import { afterEach, describe, expect, test, vi } from "vitest";
import { CheckoutDismissedError, CheckoutFailedError, openRazorpayCheckout } from "@/lib/payments/checkout";

const init = {
  key_id: "rzp_test_x",
  gateway_order_id: "order_x",
  amount: 500,
  currency: "GBP",
  reference: "RG-1",
  name: "RaffleGuru",
};

describe("openRazorpayCheckout", () => {
  afterEach(() => {
    delete (window as unknown as { Razorpay?: unknown }).Razorpay;
    document.querySelectorAll("script").forEach((s) => s.remove());
  });

  test("injects the Razorpay script and configures it with the gateway's checkout data", async () => {
    let capturedOptions: Record<string, unknown> | null = null;
    const open = vi.fn();
    const on = vi.fn();

    class FakeRazorpay {
      constructor(options: Record<string, unknown>) {
        capturedOptions = options;
      }
      open = open;
      on = on;
    }

    const promise = openRazorpayCheckout(init);

    // Simulate the script tag loading.
    const script = document.querySelector('script[src="https://checkout.razorpay.com/v1/checkout.js"]');
    expect(script).not.toBeNull();
    (window as unknown as { Razorpay: unknown }).Razorpay = FakeRazorpay;
    script?.dispatchEvent(new Event("load"));

    // Fire the success handler as Razorpay Checkout itself would.
    await Promise.resolve();
    const options = capturedOptions as unknown as {
      key: string;
      amount: number;
      currency: string;
      order_id: string;
      handler: (r: unknown) => void;
    };
    expect(options.key).toBe("rzp_test_x");
    expect(options.amount).toBe(500);
    expect(options.order_id).toBe("order_x");
    expect(open).toHaveBeenCalledTimes(1);

    const response = { razorpay_order_id: "order_x", razorpay_payment_id: "pay_x", razorpay_signature: "sig" };
    options.handler(response);

    await expect(promise).resolves.toEqual(response);
  });

  test("rejects with CheckoutDismissedError when the modal is dismissed", async () => {
    class FakeRazorpay {
      options: { modal?: { ondismiss?: () => void } };
      constructor(options: { modal?: { ondismiss?: () => void } }) {
        this.options = options;
      }
      open() {
        this.options.modal?.ondismiss?.();
      }
      on() {}
    }
    (window as unknown as { Razorpay: unknown }).Razorpay = FakeRazorpay;

    const promise = openRazorpayCheckout(init);
    const script = document.querySelector('script[src="https://checkout.razorpay.com/v1/checkout.js"]');
    script?.dispatchEvent(new Event("load"));

    await expect(promise).rejects.toBeInstanceOf(CheckoutDismissedError);
  });

  test("rejects with CheckoutFailedError when Razorpay reports payment.failed", async () => {
    const captured: { failedHandler: ((r: unknown) => void) | null } = { failedHandler: null };

    class FakeRazorpay {
      open() {}
      on(event: string, handler: (r: unknown) => void) {
        if (event === "payment.failed") captured.failedHandler = handler;
      }
    }
    (window as unknown as { Razorpay: unknown }).Razorpay = FakeRazorpay;

    const promise = openRazorpayCheckout(init);
    const script = document.querySelector('script[src="https://checkout.razorpay.com/v1/checkout.js"]');
    script?.dispatchEvent(new Event("load"));
    await Promise.resolve();

    captured.failedHandler?.({ error: { code: "BAD_REQUEST_ERROR", description: "Card declined." } });

    await expect(promise).rejects.toBeInstanceOf(CheckoutFailedError);
    await expect(promise).rejects.toThrow("Card declined.");
  });
});
