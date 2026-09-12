import type { ComponentPropsWithoutRef, ElementType } from "react";

type ContainerProps<T extends ElementType> = {
  as?: T;
  /** drop the horizontal gutter (rare — full-bleed children) */
  bleed?: boolean;
  className?: string;
} & Omit<ComponentPropsWithoutRef<T>, "as" | "className">;

/**
 * The single site content column.
 * max-width 80rem (`--container-site`) with the standard responsive gutter.
 * Every page section renders its content inside a <Container>.
 */
export default function Container<T extends ElementType = "div">({
  as,
  bleed = false,
  className = "",
  ...props
}: ContainerProps<T>) {
  const Tag = (as ?? "div") as ElementType;
  return (
    <Tag
      className={`mx-auto w-full max-w-7xl ${
        bleed ? "" : "px-4 sm:px-6 xl:px-8"
      } ${className}`}
      {...props}
    />
  );
}
