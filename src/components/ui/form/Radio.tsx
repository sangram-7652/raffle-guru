import type { ComponentPropsWithoutRef, ReactNode } from "react";

type RadioProps = Omit<ComponentPropsWithoutRef<"input">, "type"> & {
  label?: ReactNode;
  description?: ReactNode;
};

export default function Radio({
  label,
  description,
  className = "",
  id,
  ...props
}: RadioProps) {
  const control = (
    <input
      type="radio"
      id={id}
      className={`mt-0.5 h-4 w-4 shrink-0 border-line accent-pink-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pink/40 disabled:opacity-60 ${className}`}
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
