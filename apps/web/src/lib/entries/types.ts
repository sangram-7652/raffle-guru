/** Mirrors App\Http\Resources\EntryResource (P7). `ticket_numbers` are
 * the customer-facing raffle numbers — never an internal database id. */
export type ApiEntry = {
  id: number;
  competition: { id: number; slug: string; title: string };
  order_reference: string;
  quantity: number;
  ticket_numbers: number[];
  allocated_at: string | null;
};

export type SingleResponse<T> = { data: T };
export type PaginationMeta = {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
};
export type PaginatedResponse<T> = { data: T[]; meta: PaginationMeta };
