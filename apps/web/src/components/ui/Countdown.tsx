"use client";

import { useSyncExternalStore } from "react";

type CountdownProps = {
  /** ISO date string or Date — the draw moment */
  target: string | Date;
  /** compact single-line style vs. boxed digit groups */
  variant?: "inline" | "blocks";
  className?: string;
  /** label above the blocks variant */
  label?: string;
};

type Parts = { days: number; hours: number; minutes: number; seconds: number };

function parts(msLeft: number): Parts {
  const ms = Math.max(0, msLeft);
  return {
    days: Math.floor(ms / 86_400_000),
    hours: Math.floor((ms / 3_600_000) % 24),
    minutes: Math.floor((ms / 60_000) % 60),
    seconds: Math.floor((ms / 1000) % 60),
  };
}

const pad = (n: number) => String(n).padStart(2, "0");

/* A shared 1s ticker via useSyncExternalStore so the value hydrates
   cleanly (server renders a stable placeholder, client takes over
   after mount) without any setState-in-effect. */
function subscribe(onChange: () => void) {
  const id = window.setInterval(onChange, 1000);
  return () => window.clearInterval(id);
}
const getSnapshot = () => Date.now();
const getServerSnapshot = () => 0;

/**
 * Presentation countdown — Phase 1 has no backend or real-time data.
 * The parent passes a static target date (mock data); this only ticks
 * for display polish.
 */
export default function Countdown({
  target,
  variant = "inline",
  className = "",
  label,
}: CountdownProps) {
  const targetMs = (typeof target === "string" ? new Date(target) : target).getTime();
  const now = useSyncExternalStore(subscribe, getSnapshot, getServerSnapshot);
  const ready = now !== 0;
  const p = parts(targetMs - now);

  const a11y = ready
    ? `${p.days}d ${p.hours}h ${p.minutes}m ${p.seconds}s remaining`
    : "Countdown loading";

  if (variant === "inline") {
    return (
      <span
        className={`font-mono text-sm tabular-nums text-heading ${className}`}
        aria-label={a11y}
      >
        {ready ? (
          <>
            {p.days > 0 && `${p.days}d `}
            {pad(p.hours)}:{pad(p.minutes)}:{pad(p.seconds)}
          </>
        ) : (
          "--:--:--"
        )}
      </span>
    );
  }

  const groups: Array<[string, number]> = [
    ["Days", p.days],
    ["Hrs", p.hours],
    ["Min", p.minutes],
    ["Sec", p.seconds],
  ];

  return (
    <div className={className}>
      {label && <p className="t-eyebrow mb-2 text-muted">{label}</p>}
      <div className="flex gap-2" aria-label={a11y}>
        {groups.map(([name, value]) => (
          <div
            key={name}
            className="min-w-[3.25rem] rounded-md border border-line bg-panel px-2 py-1.5 text-center"
          >
            <span className="block font-display text-lg font-extrabold tabular-nums text-heading">
              {ready ? pad(value) : "--"}
            </span>
            <span className="block text-[10px] uppercase tracking-wider text-muted">
              {name}
            </span>
          </div>
        ))}
      </div>
    </div>
  );
}
