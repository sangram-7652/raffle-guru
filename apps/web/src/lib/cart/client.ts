/**
 * Cart API client — the only module that calls /api/v1/cart*. Follows
 * the same shape as @/lib/auth/client (P3) / @/lib/competitions/client
 * (P4): centralized, typed, no fetch() calls scattered in components.
 */
import { apiRequest } from "@/lib/api/http";
import type { ApiCart, SingleResponse } from "./types";

export function getCart(): Promise<SingleResponse<ApiCart>> {
  return apiRequest<SingleResponse<ApiCart>>("/v1/cart");
}

export function addCartItem(
  competitionId: number,
  quantity: number,
): Promise<SingleResponse<ApiCart>> {
  return apiRequest<SingleResponse<ApiCart>>("/v1/cart/items", {
    method: "POST",
    body: { competition_id: competitionId, quantity },
  });
}

export function updateCartItem(
  itemId: number,
  quantity: number,
): Promise<SingleResponse<ApiCart>> {
  return apiRequest<SingleResponse<ApiCart>>(`/v1/cart/items/${itemId}`, {
    method: "PATCH",
    body: { quantity },
  });
}

export function removeCartItem(itemId: number): Promise<SingleResponse<ApiCart>> {
  return apiRequest<SingleResponse<ApiCart>>(`/v1/cart/items/${itemId}`, {
    method: "DELETE",
  });
}
