/**
 * Loads Razorpay's Checkout script on demand and wraps its callback-based
 * API in a promise. This is the ONLY module that touches `window.Razorpay`
 * — everything else in the app only ever sees `openRazorpayCheckout()`'s
 * typed result. See docs/PAYMENTS_DESIGN_P6.md.
 *
 * Razorpay Checkout's `handler` firing is never treated as "payment
 * succeeded" by itself — it only resolves with the raw gateway response,
 * which the caller must still send to POST /v1/payments/verify.
 */
import type { PaymentInitiation } from "./types";

const SCRIPT_SRC = "https://checkout.razorpay.com/v1/checkout.js";

type RazorpaySuccessResponse = {
  razorpay_order_id: string;
  razorpay_payment_id: string;
  razorpay_signature: string;
};

type RazorpayFailureResponse = {
  error?: { code?: string; description?: string };
};

type RazorpayInstance = {
  open(): void;
  on(event: "payment.failed", handler: (response: RazorpayFailureResponse) => void): void;
};

type RazorpayConstructor = new (options: {
  key: string;
  amount: number;
  currency: string;
  order_id: string;
  name: string;
  description?: string;
  handler: (response: RazorpaySuccessResponse) => void;
  modal?: { ondismiss?: () => void };
}) => RazorpayInstance;

declare global {
  interface Window {
    Razorpay?: RazorpayConstructor;
  }
}

export class CheckoutDismissedError extends Error {
  constructor() {
    super("Checkout closed before payment was completed.");
    this.name = "CheckoutDismissedError";
  }
}

export class CheckoutFailedError extends Error {
  code?: string;

  constructor(message: string, code?: string) {
    super(message);
    this.name = "CheckoutFailedError";
    this.code = code;
  }
}

let scriptPromise: Promise<void> | null = null;

function loadRazorpayScript(): Promise<void> {
  if (typeof window !== "undefined" && window.Razorpay) {
    return Promise.resolve();
  }

  if (!scriptPromise) {
    scriptPromise = new Promise((resolve, reject) => {
      const script = document.createElement("script");
      script.src = SCRIPT_SRC;
      script.async = true;
      script.onload = () => resolve();
      script.onerror = () => {
        scriptPromise = null;
        reject(new Error("Couldn't load the payment provider. Please try again."));
      };
      document.body.appendChild(script);
    });
  }

  return scriptPromise;
}

export async function openRazorpayCheckout(
  init: PaymentInitiation,
): Promise<RazorpaySuccessResponse> {
  await loadRazorpayScript();

  return new Promise((resolve, reject) => {
    const razorpay = new window.Razorpay!({
      key: init.key_id,
      amount: init.amount,
      currency: init.currency,
      order_id: init.gateway_order_id,
      name: init.name,
      description: `RaffleGuru order ${init.reference}`,
      handler: (response) => resolve(response),
      modal: {
        ondismiss: () => reject(new CheckoutDismissedError()),
      },
    });

    razorpay.on("payment.failed", (response) => {
      reject(
        new CheckoutFailedError(
          response.error?.description ?? "Payment failed.",
          response.error?.code,
        ),
      );
    });

    razorpay.open();
  });
}
