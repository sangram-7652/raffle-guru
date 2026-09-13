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

export type ApiOrder = {
  id: number;
  reference: string;
  status: ApiOrderStatus;
  currency: string;
  subtotal: number;
  total: number;
  items: ApiOrderItem[];
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
