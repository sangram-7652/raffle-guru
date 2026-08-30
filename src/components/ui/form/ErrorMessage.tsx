import type { ComponentPropsWithoutRef } from "react";
import Icon from "../Icon";

type ErrorMessageProps = ComponentPropsWithoutRef<"p">;

/** Field-level error. Pairs an icon with the text so status is not colour-only. */
export default function ErrorMessage({
  className = "",
  children,
  ...props
}: ErrorMessageProps) {
  if (!children) return null;
  return (
    <p
      className={`t-caption flex items-center gap-1.5 font-medium text-error-600 ${className}`}
      {...props}
    >
      <Icon name="close" className="h-3.5 w-3.5 shrink-0" />
      {children}
    </p>
  );
}
