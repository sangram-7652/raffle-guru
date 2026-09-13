/**
 * Payments API client — the only module that calls /api/v1/orders/*
 * /payment and /api/v1/payments/verify. Same shape as @/lib/orders/client.
 */
import { apiRequest } from "@/lib/api/http";
import type { ApiOrder, SingleResponse } from "@/lib/orders/types";
import type { PaymentInitiation } from "./types";

export function initiatePayment(reference: string): Promise<SingleResponse<PaymentInitiation>> {
  return apiRequest<SingleResponse<PaymentInitiation>>(
    `/v1/orders/${encodeURIComponent(reference)}/payment`,
    { method: "POST" },
  );
}

export type VerifyPaymentPayload = {
  razorpay_order_id: string;
  razorpay_payment_id: string;
  razorpay_signature: string;
};

export function verifyPayment(payload: VerifyPaymentPayload): Promise<SingleResponse<ApiOrder>> {
  return apiRequest<SingleResponse<ApiOrder>>("/v1/payments/verify", {
    method: "POST",
    body: payload,
  });
}
