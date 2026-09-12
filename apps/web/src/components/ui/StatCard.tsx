import type { ReactNode } from "react";

type StatCardProps = {
  value: ReactNode;
  label: ReactNode;
  /** `plain` = bare figure (hero strip); `card` = bordered surface */
  variant?: "plain" | "card";
  className?: string;
};

/**
 * A single headline metric (value + label). Used for the hero stat strip
 * and anywhere a small KPI needs to read consistently.
 */
export default function StatCard({
  value,
  label,
  variant = "plain",
  className = "",
}: StatCardProps) {
  return (
    <div
      className={`flex flex-col ${
        variant === "card"
          ? "rounded-card border border-line bg-card p-5 card-shadow"
          : ""
      } ${className}`}
    >
      <span className="order-1 font-display text-xl font-extrabold text-heading sm:text-2xl">
        {value}
      </span>
      <span className="order-2 t-caption text-muted">{label}</span>
    </div>
  );
}
