import type { ReactNode } from "react";
import Icon from "@/components/ui/Icon";

type SummaryCardProps = {
  label: string;
  /** A number, a dash while loading or failed, or a skeleton. */
  value: ReactNode;
  icon: "ticket" | "gift" | "clock" | "trophy";
};

/** One headline figure on the overview. The value is always real data or
 * an honest placeholder — the caller decides which. */
export default function SummaryCard({ label, value, icon }: SummaryCardProps) {
  return (
    <div className="flex flex-col gap-4 rounded-card border border-line bg-card p-4 card-shadow sm:p-5">
      <div className="flex items-center justify-between gap-3">
        <span className="t-eyebrow text-muted">{label}</span>
        <span className="grid h-9 w-9 shrink-0 place-items-center rounded-xl border border-line bg-mist text-gold-700">
          <Icon name={icon} className="h-4 w-4" />
        </span>
      </div>
      <span className="font-display text-3xl font-extrabold leading-none tracking-tight tabular-nums text-heading sm:text-4xl">
        {value}
      </span>
    </div>
  );
}
