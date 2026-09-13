/** What POST /v1/orders/{reference}/payment returns — only what
 * Razorpay Checkout needs in the browser. Never a gateway secret. */
export type PaymentInitiation = {
  key_id: string;
  gateway_order_id: string;
  amount: number;
  currency: string;
  reference: string;
  name: string;
};

export type SingleResponse<T> = { data: T };
