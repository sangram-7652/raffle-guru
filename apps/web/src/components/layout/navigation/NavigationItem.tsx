import Link from "next/link";
import type { ComponentPropsWithoutRef } from "react";

type NavigationItemProps = ComponentPropsWithoutRef<"a"> & {
  href: string;
  /** visual treatment: inline desktop link vs. large stacked mobile row */
  size?: "desktop" | "mobile";
  /** highlight the current section */
  active?: boolean;
};

export default function NavigationItem({
  size = "desktop",
  active = false,
  href,
  className = "",
  ...props
}: NavigationItemProps) {
  const styles =
    size === "desktop"
      ? `text-sm font-medium transition-colors hover:text-heading focus-visible:outline-none focus-visible:text-heading ${
          active ? "text-heading" : "text-muted"
        }`
      : `border-b border-line py-4 font-display text-2xl font-bold tracking-tight ${
          active ? "text-gold-700" : "text-heading"
        }`;
  return (
    <Link
      href={href}
      aria-current={active ? "page" : undefined}
      className={`${styles} ${className}`}
      {...props}
    />
  );
}
