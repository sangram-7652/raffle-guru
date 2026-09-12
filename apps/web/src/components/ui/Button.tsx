import Link from "next/link";
import type { ComponentPropsWithoutRef, ReactNode } from "react";

export type ButtonVariant =
  | "primary"
  | "secondary"
  | "outline"
  | "ghost"
  | "danger";
export type ButtonSize = "sm" | "md" | "lg";

type CommonProps = {
  variant?: ButtonVariant;
  size?: ButtonSize;
  /** stretch to the width of the parent */
  block?: boolean;
  /** show a spinner and block interaction */
  loading?: boolean;
  className?: string;
  children: ReactNode;
};

/** Anchor when `href` is set, otherwise a real <button>. */
type ButtonAsLink = CommonProps &
  Omit<ComponentPropsWithoutRef<"a">, "className" | "children"> & {
    href: string;
  };
type ButtonAsButton = CommonProps &
  Omit<ComponentPropsWithoutRef<"button">, "className" | "children"> & {
    href?: undefined;
  };

type ButtonProps = ButtonAsLink | ButtonAsButton;

const base =
  "inline-flex items-center justify-center gap-2 rounded-full font-display font-bold tracking-tight whitespace-nowrap transition-all duration-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pink/70 focus-visible:ring-offset-2 focus-visible:ring-offset-paper active:scale-[0.98] disabled:pointer-events-none disabled:opacity-50 aria-disabled:pointer-events-none aria-disabled:opacity-50";

const variants: Record<ButtonVariant, string> = {
  primary:
    "brand-gradient text-white shadow-[var(--shadow-cta)] hover:shadow-[0_16px_40px_-8px_var(--color-pink)] hover:-translate-y-0.5",
  secondary:
    "border border-line bg-mist text-heading backdrop-blur hover:border-pink/60 hover:bg-panel",
  outline:
    "border border-pink-600/40 bg-transparent text-pink-700 hover:border-pink-600 hover:bg-pink-600/5",
  ghost: "text-heading hover:text-pink-600",
  danger:
    "bg-error text-white hover:bg-error-600 shadow-[0_10px_30px_-12px_var(--color-error)] hover:-translate-y-0.5",
};

const sizes: Record<ButtonSize, string> = {
  sm: "h-9 px-4 text-sm",
  md: "h-11 px-6 text-sm",
  lg: "h-14 px-8 text-base",
};

function Spinner() {
  return (
    <svg
      className="h-4 w-4 animate-spin"
      viewBox="0 0 24 24"
      fill="none"
      aria-hidden="true"
    >
      <circle
        cx="12"
        cy="12"
        r="9"
        stroke="currentColor"
        strokeWidth="2.5"
        className="opacity-25"
      />
      <path
        d="M21 12a9 9 0 0 0-9-9"
        stroke="currentColor"
        strokeWidth="2.5"
        strokeLinecap="round"
      />
    </svg>
  );
}

export default function Button({
  variant = "primary",
  size = "md",
  block = false,
  loading = false,
  className = "",
  children,
  ...props
}: ButtonProps) {
  const classes = `${base} ${variants[variant]} ${sizes[size]} ${
    block ? "w-full" : ""
  } ${className}`;
  const content = (
    <>
      {loading && <Spinner />}
      {children}
    </>
  );

  if ("href" in props && props.href !== undefined) {
    const { href, ...rest } = props;
    const internal = href.startsWith("/") || href.startsWith("#");
    if (internal) {
      return (
        <Link
          href={href}
          className={classes}
          aria-disabled={loading || undefined}
          {...rest}
        >
          {content}
        </Link>
      );
    }
    return (
      <a
        href={href}
        className={classes}
        aria-disabled={loading || undefined}
        {...rest}
      >
        {content}
      </a>
    );
  }

  const { disabled, type, ...rest } = props as ButtonAsButton;
  return (
    <button
      type={type ?? "button"}
      disabled={disabled || loading}
      className={classes}
      {...rest}
    >
      {content}
    </button>
  );
}
