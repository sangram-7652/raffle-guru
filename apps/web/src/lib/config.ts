/**
 * Frontend configuration boundary for the future Laravel API.
 *
 * Infra-only for now: nothing in the app calls this yet. It exists so the
 * environment-driven URL is available in one place once real API calls
 * are wired up, instead of hardcoding hosts anywhere.
 */
export const apiBaseUrl = process.env.NEXT_PUBLIC_API_URL ?? "";
