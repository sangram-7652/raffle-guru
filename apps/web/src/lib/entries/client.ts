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

/** Every entry across all pages — the API paginates at 10, so any total
 * (ticket counts, per-competition grouping) must read the full set. */
export async function listAllEntries(): Promise<ApiEntry[]> {
  const first = await listEntries(1);
  const rest = await Promise.all(
    Array.from({ length: Math.max(0, first.meta.last_page - 1) }, (_, i) => listEntries(i + 2)),
  );
  return [first, ...rest].flatMap((page) => page.data);
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
