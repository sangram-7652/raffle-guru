/**
 * Competition API client — the only place that calls
 * /api/v1/competitions*. Follows the same shape as
 * @/lib/auth/client (centralized request handling, typed responses,
 * typed errors) — see docs/AUTHENTICATION.md for that precedent.
 *
 * Unlike the auth client, these are all public GET endpoints — no CSRF
 * handshake needed, but the base URL differs depending on whether this
 * runs in a Server Component (needs the internal Docker route) or a
 * Client Component (needs the public one) — see resolveApiBaseUrl().
 */
import { resolveApiBaseUrl } from "@/lib/config";
import {
  CompetitionApiError,
  type ApiCompetition,
  type ListCompetitionsParams,
  type PaginatedResponse,
  type SingleResponse,
} from "./types";

async function request<T>(path: string): Promise<T> {
  const response = await fetch(`${resolveApiBaseUrl()}${path}`, {
    headers: { Accept: "application/json" },
  });

  const data = await response.json().catch(() => null);

  if (!response.ok) {
    throw new CompetitionApiError(
      data?.message ?? "Something went wrong.",
      response.status,
      data?.errors,
    );
  }

  return data as T;
}

function buildQuery(params: ListCompetitionsParams): string {
  const search = new URLSearchParams();
  if (params.search) search.set("search", params.search);
  if (params.category) search.set("category", params.category);
  if (params.status) search.set("status", params.status);
  if (params.featured !== undefined) search.set("featured", params.featured ? "1" : "0");
  if (params.sort) search.set("sort", params.sort);
  if (params.direction) search.set("direction", params.direction);
  if (params.per_page) search.set("per_page", String(params.per_page));
  if (params.page) search.set("page", String(params.page));
  const qs = search.toString();
  return qs ? `?${qs}` : "";
}

export function listCompetitions(
  params: ListCompetitionsParams = {},
): Promise<PaginatedResponse<ApiCompetition>> {
  return request<PaginatedResponse<ApiCompetition>>(`/v1/competitions${buildQuery(params)}`);
}

export async function getCompetition(slug: string): Promise<ApiCompetition | null> {
  try {
    const response = await request<SingleResponse<ApiCompetition>>(
      `/v1/competitions/${encodeURIComponent(slug)}`,
    );
    return response.data;
  } catch (error) {
    if (error instanceof CompetitionApiError && error.status === 404) {
      return null;
    }
    throw error;
  }
}
