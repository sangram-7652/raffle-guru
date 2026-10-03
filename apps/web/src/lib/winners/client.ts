/**
 * Winner/claim API client — the only module that calls
 * /api/v1/account/win*. Mirrors @/lib/entries/client's shape.
 */
import { apiRequest } from "@/lib/api/http";
import type { ApiWinner, ApiWinnerClaim, PaginatedResponse, SingleResponse } from "./types";

export function listWinners(page = 1): Promise<PaginatedResponse<ApiWinner>> {
  return apiRequest<PaginatedResponse<ApiWinner>>(`/v1/account/winners?page=${page}`);
}

export async function getCurrentWinner(): Promise<ApiWinner | null> {
  const response = await apiRequest<SingleResponse<ApiWinner | null>>("/v1/account/winner");
  return response.data;
}

export async function getWinnerClaim(winnerId: number): Promise<ApiWinnerClaim | null> {
  const response = await apiRequest<SingleResponse<ApiWinnerClaim | null>>(
    `/v1/account/winners/${winnerId}/claim`,
  );
  return response.data;
}

export function submitWinnerClaim(winnerId: number, notes: string): Promise<SingleResponse<ApiWinnerClaim>> {
  return apiRequest<SingleResponse<ApiWinnerClaim>>(`/v1/account/winners/${winnerId}/claim`, {
    method: "POST",
    body: { notes: notes.trim().length > 0 ? notes.trim() : undefined },
  });
}
