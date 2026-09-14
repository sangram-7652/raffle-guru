/**
 * Types matching the real backend API contract exactly (see
 * docs/COMPETITIONS_API.md) — not the mock-only shape in
 * @/data/competitions. `answer_index` is never present here because the
 * API never sends it (see CompetitionResource on the backend); don't add
 * it even optionally, that would invite someone to "just fake it" later.
 */

export type ApiPrize = {
  id: number;
  title: string;
  description: string[] | null;
  value: number;
  cash_alternative_value: number | null;
  currency: string;
  quantity: number;
};

export type ApiSkillQuestion = {
  prompt: string;
  options: string[];
};

/** The full backend lifecycle — see App\Enums\CompetitionStatus. Only a
 * subset (never draft/scheduled) is actually reachable through the
 * public API, but the type reflects what the column can hold. */
export type ApiCompetitionStatus =
  | "draft"
  | "scheduled"
  | "active"
  | "sold_out"
  | "closed"
  | "draw_pending"
  | "draw_in_progress"
  | "drawn"
  | "completed";

export type ApiCompetition = {
  id: number;
  slug: string;
  title: string;
  short_description: string;
  description: string[] | null;
  category: string;
  status: ApiCompetitionStatus;
  /** Minor units (pence) — see docs/SCHEMA_DESIGN_P2.md. Divide by 100
   * before passing to lib/format's gbp(). */
  entry_price: number;
  currency: string;
  max_entries: number;
  max_entries_per_user: number | null;
  images: string[] | null;
  skill_question: ApiSkillQuestion | null;
  starts_at: string | null;
  ends_at: string | null;
  draw_at: string | null;
  is_featured: boolean;
  prizes: ApiPrize[];
  /** P9 — present only once `status === "drawn"`; absent otherwise.
   * See docs/DRAW_ENGINE_P9.md "Public API behavior": deliberately just
   * these two facts, nothing about who won (that's P10's). */
  draw?: {
    ticket_number: number;
    drawn_at: string;
  };
};

export type PaginationMeta = {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
};

export type PaginatedResponse<T> = {
  data: T[];
  meta: PaginationMeta;
};

export type SingleResponse<T> = {
  data: T;
};

export type CompetitionSortKey = "ending_soon" | "newest" | "price" | "featured";

export type ListCompetitionsParams = {
  search?: string;
  category?: string;
  status?: ApiCompetitionStatus;
  featured?: boolean;
  sort?: CompetitionSortKey;
  direction?: "asc" | "desc";
  per_page?: number;
  page?: number;
};

export class CompetitionApiError extends Error {
  status: number;
  errors?: Record<string, string[]>;

  constructor(message: string, status: number, errors?: Record<string, string[]>) {
    super(message);
    this.name = "CompetitionApiError";
    this.status = status;
    this.errors = errors;
  }
}
