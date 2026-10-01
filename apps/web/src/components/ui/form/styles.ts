/** Shared field styling so every form control reads identically. */

export type FieldState = "default" | "error" | "success";

export const controlBase =
  "w-full rounded-md border bg-card text-heading transition-colors placeholder:text-muted/70 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold/40 disabled:cursor-not-allowed disabled:bg-panel disabled:opacity-60";

export const controlSize = "h-11 px-3.5 text-sm";

export const stateBorder: Record<FieldState, string> = {
  default: "border-line focus-visible:border-gold",
  error: "border-error focus-visible:border-error focus-visible:ring-error/30",
  success:
    "border-success focus-visible:border-success focus-visible:ring-success/30",
};

export function fieldClass(state: FieldState = "default", extra = "") {
  return `${controlBase} ${controlSize} ${stateBorder[state]} ${extra}`;
}
