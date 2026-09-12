import type { ComponentPropsWithoutRef, ReactNode } from "react";
import Container from "./Container";

type Surface = "paper" | "mist" | "ink";
type SpaceY = "default" | "sm" | "none";

type SectionProps = {
  children: ReactNode;
  /** background surface token */
  surface?: Surface;
  /** vertical padding rhythm */
  spaceY?: SpaceY;
  /** hairline top + bottom border (used by alternating mist sections) */
  bordered?: boolean;
  /** render the inner Container automatically (default true) */
  contained?: boolean;
  className?: string;
  /** class applied to the inner Container when `contained` */
  innerClassName?: string;
} & Omit<ComponentPropsWithoutRef<"section">, "className" | "children">;

const surfaces: Record<Surface, string> = {
  paper: "bg-paper",
  mist: "bg-mist",
  ink: "bg-ink text-white",
};

const spacing: Record<SpaceY, string> = {
  default: "section-y",
  sm: "section-y-sm",
  none: "",
};

/**
 * Full-width page section: surface + vertical rhythm + optional borders,
 * wrapping its children in the site Container.
 */
export default function Section({
  children,
  surface = "paper",
  spaceY = "default",
  bordered = false,
  contained = true,
  className = "",
  innerClassName = "",
  ...props
}: SectionProps) {
  return (
    <section
      className={`relative ${surfaces[surface]} ${spacing[spaceY]} ${
        bordered ? "border-y border-line" : ""
      } ${className}`}
      {...props}
    >
      {contained ? (
        <Container className={innerClassName}>{children}</Container>
      ) : (
        children
      )}
    </section>
  );
}
