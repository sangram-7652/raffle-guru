import type { ComponentPropsWithoutRef } from "react";
import { controlBase, stateBorder, type FieldState } from "./styles";

type TextareaProps = ComponentPropsWithoutRef<"textarea"> & {
  state?: FieldState;
};

export default function Textarea({
  state = "default",
  className = "",
  rows = 4,
  ...props
}: TextareaProps) {
  return (
    <textarea
      rows={rows}
      className={`${controlBase} min-h-[6rem] resize-y px-3.5 py-2.5 text-sm ${stateBorder[state]} ${className}`}
      aria-invalid={state === "error" || undefined}
      {...props}
    />
  );
}
