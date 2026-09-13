/**
 * Orders API client — the only module that calls /api/v1/orders*. Same
 * shape as @/lib/cart/client.
 */
import { apiRequest, ApiError } from "@/lib/api/http";
import type { ApiOrder, PaginatedResponse, SingleResponse } from "./types";

export function createOrder(): Promise<SingleResponse<ApiOrder>> {
  return apiRequest<SingleResponse<ApiOrder>>("/v1/orders", { method: "POST" });
}

export function listOrders(page = 1): Promise<PaginatedResponse<ApiOrder>> {
  return apiRequest<PaginatedResponse<ApiOrder>>(`/v1/orders?page=${page}`);
}

export async function getOrder(reference: string): Promise<ApiOrder | null> {
  try {
    const response = await apiRequest<SingleResponse<ApiOrder>>(
      `/v1/orders/${encodeURIComponent(reference)}`,
    );
    return response.data;
  } catch (error) {
    if (error instanceof ApiError && error.status === 404) {
      return null;
    }
    throw error;
  }
}
