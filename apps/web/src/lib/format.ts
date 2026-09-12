export function gbp(amount: number): string {
  return new Intl.NumberFormat("en-GB", {
    style: "currency",
    currency: "GBP",
    minimumFractionDigits: amount % 1 === 0 ? 0 : 2,
  }).format(amount);
}

/** "Sunday 14 July, 9:00 PM" — deterministic (fixed en-GB + UTC) so it
 *  renders identically on server and client. */
export function drawDateLong(iso: string): string {
  return new Intl.DateTimeFormat("en-GB", {
    weekday: "long",
    day: "numeric",
    month: "long",
    hour: "numeric",
    minute: "2-digit",
    hour12: true,
    timeZone: "UTC",
  }).format(new Date(iso));
}

/** "14 Jul 2025" */
export function dateMedium(iso: string): string {
  return new Intl.DateTimeFormat("en-GB", {
    day: "numeric",
    month: "short",
    year: "numeric",
    timeZone: "UTC",
  }).format(new Date(iso));
}

/** Whole-number percentage, clamped 0–100. */
export function pct(n: number): number {
  return Math.min(100, Math.max(0, Math.round(n)));
}
