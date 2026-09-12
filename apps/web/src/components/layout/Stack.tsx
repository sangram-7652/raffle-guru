import type { ComponentPropsWithoutRef, ElementType } from "react";

type Gap = 0 | 1 | 2 | 3 | 4 | 5 | 6 | 8 | 10 | 12;
type Align = "start" | "center" | "end" | "stretch";
type Justify = "start" | "center" | "end" | "between";

type StackProps<T extends ElementType> = {
  as?: T;
  /** flex direction — row or column (default column) */
  direction?: "row" | "col";
  gap?: Gap;
  align?: Align;
  justify?: Justify;
  wrap?: boolean;
  className?: string;
} & Omit<ComponentPropsWithoutRef<T>, "as" | "className">;

const gapMap: Record<Gap, string> = {
  0: "gap-0",
  1: "gap-1",
  2: "gap-2",
  3: "gap-3",
  4: "gap-4",
  5: "gap-5",
  6: "gap-6",
  8: "gap-8",
  10: "gap-10",
  12: "gap-12",
};
const alignMap: Record<Align, string> = {
  start: "items-start",
  center: "items-center",
  end: "items-end",
  stretch: "items-stretch",
};
const justifyMap: Record<Justify, string> = {
  start: "justify-start",
  center: "justify-center",
  end: "justify-end",
  between: "justify-between",
};

/**
 * Thin flexbox helper for one-dimensional layouts.
 * Use raw grid utilities for 2D card grids (see CompetitionGrid).
 */
export default function Stack<T extends ElementType = "div">({
  as,
  direction = "col",
  gap = 4,
  align = "stretch",
  justify = "start",
  wrap = false,
  className = "",
  ...props
}: StackProps<T>) {
  const Tag = (as ?? "div") as ElementType;
  return (
    <Tag
      className={`flex ${direction === "col" ? "flex-col" : "flex-row"} ${
        gapMap[gap]
      } ${alignMap[align]} ${justifyMap[justify]} ${
        wrap ? "flex-wrap" : ""
      } ${className}`}
      {...props}
    />
  );
}
