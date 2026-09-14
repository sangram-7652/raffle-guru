import type { ApiCompetitionStatus } from "@/lib/competitions/types";

/** Mirrors App\Http\Resources\EntryResource (P7/P9). `ticket_numbers`
 * are the customer-facing raffle numbers — never an internal database
 * id. `competition.status` (P9) is read-only lifecycle state only —
 * this never says whether this entry's own tickets won (that's P10's). */
export type ApiEntry = {
  id: number;
  competition: { id: number; slug: string; title: string; status: ApiCompetitionStatus };
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
