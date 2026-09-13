/**
 * Entries API client — the only module that calls /api/v1/entries*.
 * Read-only: there is no mutation endpoint, and none should be added
 * (customers cannot edit/delete entries or tickets). Same shape as
 * @/lib/orders/client.
 */
import { apiRequest, ApiError } from "@/lib/api/http";
import type { ApiEntry, PaginatedResponse, SingleResponse } from "./types";

export function listEntries(page = 1): Promise<PaginatedResponse<ApiEntry>> {
  return apiRequest<PaginatedResponse<ApiEntry>>(`/v1/entries?page=${page}`);
}

export async function getEntry(id: number): Promise<ApiEntry | null> {
  try {
    const response = await apiRequest<SingleResponse<ApiEntry>>(`/v1/entries/${id}`);
    return response.data;
  } catch (error) {
    if (error instanceof ApiError && error.status === 404) {
      return null;
    }
    throw error;
  }
}
