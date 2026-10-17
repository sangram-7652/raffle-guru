/**
 * Promotion API client — public read of a promotion's open/closed
 * state, plus the authenticated claim-status/claim actions. Mirrors
 * @/lib/winners/client's GET+POST claim shape. See
 * docs/EMAIL_CAPTURE.md "Option 1 proposal".
 */
import { apiRequest } from "@/lib/api/http";

/**
 * The one promotion the footer email-capture band advertises. An admin
 * must create a Promotion with exactly this slug (Filament) for the
 * "Claim Your Free Entry" CTA to ever become live — see
 * docs/EMAIL_CAPTURE.md.
 */
export const FOOTER_PROMOTION_SLUG = "welcome-ticket";

export type Promotion = {
  slug: string;
  name: string;
  is_open: boolean;
};

export type PromotionClaim = {
  status: "pending" | "fulfilled" | "failed";
  failure_reason: string | null;
  entry_id: number | null;
};

export async function getPromotion(slug: string): Promise<Promotion | null> {
  const response = await apiRequest<{ data: Promotion | null }>(`/v1/promotions/${slug}`);
  return response.data;
}

export async function getPromotionClaim(slug: string): Promise<PromotionClaim | null> {
  const response = await apiRequest<{ data: PromotionClaim | null }>(`/v1/account/promotions/${slug}/claim`);
  return response.data;
}

export async function claimPromotion(slug: string): Promise<PromotionClaim> {
  const response = await apiRequest<{ data: PromotionClaim }>(`/v1/account/promotions/${slug}/claim`, {
    method: "POST",
  });
  return response.data;
}
