import type { ComponentPropsWithoutRef } from "react";

type LabelProps = ComponentPropsWithoutRef<"label"> & {
  /** show a subtle required marker */
  required?: boolean;
  /** render as a non-label element (e.g. group legend text) */
  optional?: boolean;
};

export default function Label({
  required = false,
  optional = false,
  className = "",
  children,
  ...props
}: LabelProps) {
  return (
    <label className={`t-label block text-heading ${className}`} {...props}>
      {children}
      {required && (
        <span className="ml-0.5 text-error" aria-hidden="true">
          *
        </span>
      )}
      {optional && (
        <span className="ml-1 font-normal text-muted">(optional)</span>
      )}
    </label>
  );
}
