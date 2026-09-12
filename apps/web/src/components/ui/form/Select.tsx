import type { ComponentPropsWithoutRef } from "react";
import { fieldClass, type FieldState } from "./styles";

type SelectProps = Omit<ComponentPropsWithoutRef<"select">, "size"> & {
  state?: FieldState;
};

export default function Select({
  state = "default",
  className = "",
  children,
  ...props
}: SelectProps) {
  return (
    <div className="relative">
      <select
        className={fieldClass(state, `appearance-none pr-10 ${className}`)}
        aria-invalid={state === "error" || undefined}
        {...props}
      >
        {children}
      </select>
      <svg
        viewBox="0 0 24 24"
        className="pointer-events-none absolute right-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.8"
        strokeLinecap="round"
        strokeLinejoin="round"
        aria-hidden="true"
      >
        <path d="M6 9l6 6 6-6" />
      </svg>
    </div>
  );
}
