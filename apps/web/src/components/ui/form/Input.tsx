import type { ComponentPropsWithoutRef } from "react";
import { fieldClass, type FieldState } from "./styles";

type InputProps = Omit<ComponentPropsWithoutRef<"input">, "size"> & {
  state?: FieldState;
};

export default function Input({
  state = "default",
  className = "",
  type = "text",
  ...props
}: InputProps) {
  return (
    <input
      type={type}
      className={fieldClass(state, className)}
      aria-invalid={state === "error" || undefined}
      {...props}
    />
  );
}
