"use client";

import { useId } from "react";
import Icon from "./Icon";

type QuantityStepperProps = {
  value: number;
  onChange: (next: number) => void;
  min?: number;
  max?: number;
  step?: number;
  /** accessible name for the number input */
  label: string;
  size?: "md" | "lg";
  className?: string;
};

export default function QuantityStepper({
  value,
  onChange,
  min = 1,
  max = 999,
  step = 1,
  label,
  size = "md",
  className = "",
}: QuantityStepperProps) {
  const id = useId();
  const clamp = (n: number) => Math.min(max, Math.max(min, Math.round(n)));
  const dims =
    size === "lg" ? "h-12 [&_button]:w-12 text-base" : "h-10 [&_button]:w-10 text-sm";

  return (
    <div
      className={`inline-flex items-stretch overflow-hidden rounded-full border border-line bg-card ${dims} ${className}`}
    >
      <button
        type="button"
        aria-label={`Decrease ${label}`}
        disabled={value <= min}
        onClick={() => onChange(clamp(value - step))}
        className="grid place-items-center text-heading transition-colors hover:bg-panel disabled:opacity-30 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-gold-700/50"
      >
        <Icon name="close" className="h-3.5 w-3.5 rotate-45" />
      </button>
      <label htmlFor={id} className="sr-only">
        {label}
      </label>
      <input
        id={id}
        type="number"
        inputMode="numeric"
        min={min}
        max={max}
        step={step}
        value={value}
        onChange={(e) => {
          const n = Number(e.target.value);
          if (!Number.isNaN(n)) onChange(clamp(n));
        }}
        className="w-14 border-x border-line bg-transparent text-center font-display font-bold tabular-nums text-heading focus-visible:outline-none focus-visible:bg-panel [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none"
      />
      <button
        type="button"
        aria-label={`Increase ${label}`}
        disabled={value >= max}
        onClick={() => onChange(clamp(value + step))}
        className="grid place-items-center text-heading transition-colors hover:bg-panel disabled:opacity-30 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-gold-700/50"
      >
        <span aria-hidden className="text-lg leading-none">
          +
        </span>
      </button>
    </div>
  );
}
