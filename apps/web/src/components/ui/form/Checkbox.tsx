import type { ComponentPropsWithoutRef, ReactNode } from "react";

type CheckboxProps = Omit<ComponentPropsWithoutRef<"input">, "type"> & {
  label?: ReactNode;
  description?: ReactNode;
};

/**
 * Checkbox with an inline label. Uses `accent-color` so the native control
 * stays fully keyboard/AT accessible while picking up the brand colour.
 */
export default function Checkbox({
  label,
  description,
  className = "",
  id,
  ...props
}: CheckboxProps) {
  const control = (
    <input
      type="checkbox"
      id={id}
      className={`mt-0.5 h-4 w-4 shrink-0 rounded border-line accent-gold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold-700/40 disabled:opacity-60 ${className}`}
      {...props}
    />
  );

  if (!label) return control;

  return (
    <label
      htmlFor={id}
      className="flex cursor-pointer items-start gap-2.5 has-[:disabled]:cursor-not-allowed has-[:disabled]:opacity-60"
    >
      {control}
      <span>
        <span className="t-body-sm block text-heading">{label}</span>
        {description && (
          <span className="t-caption block text-muted">{description}</span>
        )}
      </span>
    </label>
  );
}
