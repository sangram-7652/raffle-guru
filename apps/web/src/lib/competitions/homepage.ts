/**
 * Loads one homepage competition section from the public API. The
 * definition of "featured" and "ending soon" lives in the backend query
 * (see HomepageQueryTest); this only chooses the parameters and turns the
 * result into an explicit state the UI can render. Never falls back to
 * mock competitions — a failed request is reported as an error.
 */
import { adaptCompetition } from "./adapter";
import { listCompetitions } from "./client";
import type { ListCompetitionsParams } from "./types";
import type { Competition } from "@/data/competitions";

export type HomepageCompetitionsResult =
  | { state: "ready"; competitions: Competition[] }
  | { state: "empty" }
  | { state: "error" };

/** A hung API must not hold the whole homepage open. */
const SECTION_TIMEOUT_MS = 8000;

function withTimeout<T>(promise: Promise<T>, ms: number): Promise<T> {
  let timer: ReturnType<typeof setTimeout> | undefined;
  const timeout = new Promise<never>((_, reject) => {
    timer = setTimeout(() => reject(new Error("timed out")), ms);
  });
  return Promise.race([promise, timeout]).finally(() => clearTimeout(timer));
}

export async function loadHomepageCompetitions(
  params: ListCompetitionsParams,
): Promise<HomepageCompetitionsResult> {
  try {
    const { data } = await withTimeout(listCompetitions(params), SECTION_TIMEOUT_MS);
    if (data.length === 0) return { state: "empty" };
    return { state: "ready", competitions: data.map(adaptCompetition) };
  } catch {
    return { state: "error" };
  }
}

/** Live competitions the customer can still enter, headline prizes first. */
export const featuredSectionParams: ListCompetitionsParams = {
  featured: true,
  status: "active",
  sort: "ending_soon",
  per_page: 4,
};

/** Live competitions closing soonest — same status and draw ordering the
 * listing page uses for its "Ending soonest" sort. */
export const endingSoonSectionParams: ListCompetitionsParams = {
  status: "active",
  sort: "ending_soon",
  per_page: 3,
};
