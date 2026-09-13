/**
 * Frontend configuration boundary for the Laravel API.
 *
 * Both derive from the same origin (Nginx serves web + api together — see
 * docs/PRODUCTION_ARCHITECTURE.md) so there's only one env var to set.
 */
export const apiBaseUrl = process.env.NEXT_PUBLIC_API_URL ?? "";

/** Origin with any trailing "/api" removed — for routes Sanctum registers
 * outside the /api prefix, e.g. GET /sanctum/csrf-cookie. */
export const apiOrigin = apiBaseUrl.replace(/\/api\/?$/, "");
