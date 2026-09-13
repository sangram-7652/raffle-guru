/**
 * Frontend configuration boundary for the Laravel API.
 *
 * Both derive from the same origin (Nginx serves web + api together — see
 * docs/PRODUCTION_ARCHITECTURE.md) so there's only one env var to set for
 * the browser.
 */
export const apiBaseUrl = process.env.NEXT_PUBLIC_API_URL ?? "";

/** Origin with any trailing "/api" removed — for routes Sanctum registers
 * outside the /api prefix, e.g. GET /sanctum/csrf-cookie. */
export const apiOrigin = apiBaseUrl.replace(/\/api\/?$/, "");

/**
 * Server-only base URL for Server Components / generateMetadata /
 * generateStaticParams. These run inside the Next.js container itself,
 * which cannot reach the public NEXT_PUBLIC_API_URL (that's Nginx's
 * public port — from inside the `web` container there's nothing
 * listening on it): they need the API's internal Docker address
 * directly. Never read this from a Client Component — there's no
 * NEXT_PUBLIC_ prefix, so it isn't bundled to the browser at all.
 */
export const apiInternalBaseUrl = process.env.API_INTERNAL_URL ?? apiBaseUrl;

/** Picks the right base URL for the environment this code is running in. */
export function resolveApiBaseUrl(): string {
  return typeof window === "undefined" ? apiInternalBaseUrl : apiBaseUrl;
}
