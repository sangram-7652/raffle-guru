import type { ComponentPropsWithoutRef } from "react";

type HelperTextProps = ComponentPropsWithoutRef<"p">;

export default function HelperText({
  className = "",
  ...props
}: HelperTextProps) {
  return <p className={`t-caption text-muted ${className}`} {...props} />;
}
