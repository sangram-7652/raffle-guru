import type { ComponentPropsWithoutRef } from "react";

type NavigationItemProps = ComponentPropsWithoutRef<"a"> & {
  /** visual treatment: inline desktop link vs. large stacked mobile row */
  size?: "desktop" | "mobile";
};

export default function NavigationItem({
  size = "desktop",
  className = "",
  ...props
}: NavigationItemProps) {
  const styles =
    size === "desktop"
      ? "text-sm font-medium text-muted transition-colors hover:text-heading focus-visible:outline-none focus-visible:text-heading"
      : "border-b border-line py-4 font-display text-2xl font-bold tracking-tight text-heading";
  return <a className={`${styles} ${className}`} {...props} />;
}
