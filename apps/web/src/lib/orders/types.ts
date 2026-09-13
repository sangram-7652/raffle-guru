import type { ApiEntry } from "@/lib/entries/types";

export type ApiOrderItem = {
  id: number;
  competition_id: number;
  competition_title: string;
  competition_slug: string | null;
  quantity: number;
  unit_price: number;
  currency: string;
  line_total: number;
};

/** Mirrors App\Enums\OrderStatus — only "pending" is ever set by P5;
 * the rest are P6/P7 placeholders (see docs/SCHEMA_DESIGN_P5.md). */
export type ApiOrderStatus =
  | "pending"
  | "payment_pending"
  | "paid"
  | "processing"
  | "completed"
  | "cancelled"
  | "failed"
  | "refunded";

/** Mirrors App\Enums\PaymentStatus (P6). */
export type ApiPaymentStatus = "created" | "paid" | "failed" | "refunded";

export type ApiPayment = {
  id: number;
  gateway: string;
  status: ApiPaymentStatus;
  amount: number;
  currency: string;
  failure_message: string | null;
  paid_at: string | null;
  created_at: string | null;
};

export type ApiOrder = {
  id: number;
  reference: string;
  status: ApiOrderStatus;
  currency: string;
  subtotal: number;
  total: number;
  items: ApiOrderItem[];
  /** The latest payment attempt only, or null before any exists — never
   * the full payment history. See docs/PAYMENTS_API.md. */
  payment: ApiPayment | null;
  /** Empty until ticket allocation completes (P7) — the frontend
   * distinguishes "paid, allocation in progress" from "allocated" by
   * whether this is empty, never by inferring it from the URL. See
   * docs/TICKET_ENGINE_API.md. */
  entries: ApiEntry[];
  created_at: string | null;
};

export type SingleResponse<T> = { data: T };
export type PaginationMeta = {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
};
export type PaginatedResponse<T> = { data: T[]; meta: PaginationMeta };
