import type { ComponentPropsWithoutRef } from "react";

type Variant = "primary" | "secondary" | "ghost";
type Size = "sm" | "md" | "lg";

type ButtonProps = ComponentPropsWithoutRef<"a"> & {
  variant?: Variant;
  size?: Size;
};

const base =
  "inline-flex items-center justify-center gap-2 rounded-full font-display font-bold tracking-tight transition-all duration-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pink/70 focus-visible:ring-offset-2 focus-visible:ring-offset-paper active:scale-[0.98]";

const variants: Record<Variant, string> = {
  primary:
    "brand-gradient text-white shadow-[0_10px_30px_-10px_var(--color-pink)] hover:shadow-[0_16px_40px_-8px_var(--color-pink)] hover:-translate-y-0.5",
  secondary:
    "border border-line bg-mist text-heading backdrop-blur hover:border-pink/60 hover:bg-panel",
  ghost: "text-heading hover:text-pink-600",
};

const sizes: Record<Size, string> = {
  sm: "h-9 px-4 text-sm",
  md: "h-11 px-6 text-sm",
  lg: "h-14 px-8 text-base",
};

export default function Button({
  variant = "primary",
  size = "md",
  className = "",
  ...props
}: ButtonProps) {
  return (
    <a
      className={`${base} ${variants[variant]} ${sizes[size]} ${className}`}
      {...props}
    />
  );
}
