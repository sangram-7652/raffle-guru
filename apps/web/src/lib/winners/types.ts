/**
 * Mirrors App\Http\Resources\WinnerResource / WinnerClaimResource /
 * PrizeDeliveryResource (P10) — see docs/WINNER_CLAIM_P10.md "API".
 * Every field here is server-derived; nothing about identity or status
 * is ever set from the client.
 */
export type ApiWinnerStatus =
  | "pending_contact"
  | "contacted"
  | "verification_pending"
  | "verified"
  | "claim_approved"
  | "delivery_pending"
  | "delivered"
  | "completed";

export type ApiWinnerClaimStatus =
  | "submitted"
  | "verification_pending"
  | "verified"
  | "rejected"
  | "approved";

export type ApiPrizeDeliveryStatus = "pending" | "processing" | "shipped" | "delivered" | "failed";

export type ApiWinnerClaim = {
  id: number;
  status: ApiWinnerClaimStatus;
  submitted_at: string | null;
  verified_at: string | null;
  rejected_at: string | null;
  rejection_reason: string | null;
  notes: string | null;
};

export type ApiPrizeDelivery = {
  status: ApiPrizeDeliveryStatus;
  delivery_method: string | null;
  tracking_reference: string | null;
  shipped_at: string | null;
  delivered_at: string | null;
};

export type ApiWinner = {
  id: number;
  status: ApiWinnerStatus;
  competition: { id: number; slug: string; title: string };
  winning_ticket_number: number;
  prize: { title: string; value: number; currency: string } | null;
  contacted_at: string | null;
  verified_at: string | null;
  claim_approved_at: string | null;
  delivery_pending_at: string | null;
  delivered_at: string | null;
  completed_at: string | null;
  claim: ApiWinnerClaim | null;
  delivery: ApiPrizeDelivery | null;
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
