import type { ReactNode } from "react";
import Icon from "./Icon";

export type BadgeTone =
  | "brand" /* solid gradient — highest emphasis */
  | "neutral" /* on-image / on-card chip */
  | "featured"
  | "ending-soon"
  | "live"
  | "sold-out"
  | "new"
  | "winner"
  | "success"
  | "warning"
  | "error";

type BadgeProps = {
  children: ReactNode;
  tone?: BadgeTone;
  /** leading icon; `true` picks a sensible default for the tone */
  icon?: ReactNode | boolean;
  size?: "sm" | "md";
  className?: string;
};

/* Gradient tones carry no border (pixel-identical to the approved cards);
   tinted/neutral tones add a 1px border. */
const tones: Record<BadgeTone, string> = {
  brand: "brand-gradient text-ink",
  neutral:
    "border border-line bg-card/90 text-heading shadow-sm backdrop-blur-md",
  featured: "brand-gradient text-ink",
  "ending-soon": "border border-gold-light/30 bg-gold-light/12 text-gold-light",
  live: "border border-live/30 bg-live/12 text-live",
  "sold-out": "border border-line bg-panel text-muted",
  new: "border border-success/25 bg-success/12 text-success-600",
  winner: "brand-gradient text-ink",
  success: "border border-success/25 bg-success/12 text-success-600",
  warning: "border border-warning/25 bg-warning/12 text-warning",
  error: "border border-error/25 bg-error/12 text-error-600",
};

const defaultIcons: Partial<Record<BadgeTone, "spark" | "clock" | "trophy">> = {
  featured: "spark",
  brand: "spark",
  "ending-soon": "clock",
  winner: "trophy",
};

const sizes = {
  sm: "gap-1 px-2 py-0.5 text-[10px]",
  md: "gap-1.5 px-2.5 py-1 text-[10px]",
} as const;

/**
 * Status / category pill. One component for every badge on the site so
 * hierarchy stays consistent — solid gradient for the strongest signals,
 * tinted for informational status, never colour-only (each carries a label).
 */
export default function Badge({
  children,
  tone = "neutral",
  icon = false,
  size = "md",
  className = "",
}: BadgeProps) {
  const iconNode =
    icon === true ? (
      defaultIcons[tone] ? (
        <Icon name={defaultIcons[tone]!} className="h-3 w-3" />
      ) : null
    ) : (
      icon || null
    );

  return (
    <span
      className={`inline-flex items-center rounded-full font-bold uppercase tracking-wider ${tones[tone]} ${sizes[size]} ${className}`}
    >
      {tone === "live" && (
        <span className="live-dot mr-0.5 inline-block h-1.5 w-1.5 rounded-full bg-live" />
      )}
      {iconNode}
      {children}
    </span>
  );
}
