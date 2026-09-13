"use client";

import { useCallback, useState } from "react";
import { ApiValidationError } from "@/lib/api/http";
import type { ApiOrder } from "@/lib/orders/types";
import { initiatePayment, verifyPayment } from "./client";
import { CheckoutDismissedError, CheckoutFailedError, openRazorpayCheckout } from "./checkout";

/**
 * Shared by CheckoutView (first payment attempt) and OrderConfirmation
 * ("Retry payment") — the entire initiate → Checkout → verify flow lives
 * here exactly once. Never treats Checkout's in-browser success callback
 * as the final answer: `onPaid` only fires after the server has verified
 * the payment (POST /v1/payments/verify), never before. See
 * docs/PAYMENTS_DESIGN_P6.md.
 */
export type PaymentFlowState =
  | { status: "idle" }
  | { status: "processing" }
  | { status: "verifying" }
  | { status: "dismissed" }
  | { status: "error"; message: string };

export function useRazorpayCheckout(onPaid: (order: ApiOrder) => void) {
  const [state, setState] = useState<PaymentFlowState>({ status: "idle" });

  const pay = useCallback(
    async (reference: string) => {
      setState({ status: "processing" });
      try {
        const { data: init } = await initiatePayment(reference);

        let gatewayResponse;
        try {
          gatewayResponse = await openRazorpayCheckout(init);
        } catch (err) {
          if (err instanceof CheckoutDismissedError) {
            setState({ status: "dismissed" });
            return;
          }
          setState({
            status: "error",
            message: err instanceof CheckoutFailedError ? err.message : "Payment failed.",
          });
          return;
        }

        setState({ status: "verifying" });
        const { data: order } = await verifyPayment(gatewayResponse);
        setState({ status: "idle" });
        onPaid(order);
      } catch (err) {
        setState({
          status: "error",
          message:
            err instanceof ApiValidationError
              ? (err.fieldError("payment") ?? err.fieldError("order") ?? err.message)
              : "Something went wrong starting payment. Please try again.",
        });
      }
    },
    [onPaid],
  );

  const reset = useCallback(() => setState({ status: "idle" }), []);

  return { state, pay, reset };
}
