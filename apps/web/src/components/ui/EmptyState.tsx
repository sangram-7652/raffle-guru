import type { ReactNode } from "react";
import Icon from "./Icon";

type EmptyStateProps = {
  icon?: "ticket" | "trophy" | "spark" | "gift" | "clock" | "lock";
  title: string;
  description?: ReactNode;
  action?: ReactNode;
  className?: string;
};

export default function EmptyState({
  icon = "spark",
  title,
  description,
  action,
  className = "",
}: EmptyStateProps) {
  return (
    <div
      className={`flex flex-col items-center rounded-card border border-dashed border-line bg-mist px-6 py-14 text-center ${className}`}
    >
      <span className="grid h-14 w-14 place-items-center rounded-2xl border border-line bg-card text-gold-700">
        <Icon name={icon} className="h-6 w-6" />
      </span>
      <p className="mt-5 font-display text-lg font-extrabold tracking-tight text-heading">
        {title}
      </p>
      {description && (
        <p className="mt-2 max-w-sm text-sm text-muted">{description}</p>
      )}
      {action && <div className="mt-6">{action}</div>}
    </div>
  );
}
