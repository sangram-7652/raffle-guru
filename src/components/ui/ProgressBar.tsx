"use client";

import { useEffect, useRef, useState } from "react";

type ProgressBarProps = {
  value: number; // 0 - 100
  className?: string;
};

/** Animated "% sold" bar — fills once when scrolled into view. */
export default function ProgressBar({ value, className = "" }: ProgressBarProps) {
  const ref = useRef<HTMLDivElement | null>(null);
  const [fill, setFill] = useState(0);

  useEffect(() => {
    const el = ref.current;
    if (!el) return;
    const io = new IntersectionObserver(
      ([entry]) => {
        if (entry.isIntersecting) {
          setFill(Math.min(100, Math.max(0, value)));
          io.disconnect();
        }
      },
      { threshold: 0.4 },
    );
    io.observe(el);
    return () => io.disconnect();
  }, [value]);

  return (
    <div className={className}>
      <div className="mb-1.5 flex items-center justify-between text-xs font-medium text-muted">
        <span>{Math.round(value)}% sold</span>
        <span>{100 - Math.round(value)}% left</span>
      </div>
      <div
        ref={ref}
        className="h-2 w-full overflow-hidden rounded-full bg-track"
        role="progressbar"
        aria-valuenow={Math.round(value)}
        aria-valuemin={0}
        aria-valuemax={100}
      >
        <div
          className="h-full rounded-full brand-gradient"
          style={{
            width: `${fill}%`,
            transition: "width 1.1s cubic-bezier(0.22,0.68,0.28,1)",
          }}
        />
      </div>
    </div>
  );
}
