import type { ReactNode } from "react";
import Reveal from "../ui/Reveal";

type SectionHeadingProps = {
  eyebrow?: string;
  title: ReactNode;
  subtitle?: string;
  align?: "left" | "center";
  /** heading level for the document outline (visual size is fixed) */
  as?: "h1" | "h2" | "h3";
  className?: string;
};

/**
 * The standard section header: eyebrow kicker + display title + optional
 * lede. Visual scale comes from the `.t-*` type system, never per-instance
 * font sizes.
 */
export default function SectionHeading({
  eyebrow,
  title,
  subtitle,
  align = "left",
  as: Tag = "h2",
  className = "",
}: SectionHeadingProps) {
  return (
    <Reveal
      className={`flex flex-col gap-3 ${
        align === "center" ? "items-center text-center" : "items-start"
      } ${className}`}
    >
      {eyebrow && (
        <span className="t-eyebrow inline-flex items-center gap-2 text-pink-600">
          <span className="h-px w-6 bg-pink/60" />
          {eyebrow}
        </span>
      )}
      <Tag className="t-h2 text-heading">{title}</Tag>
      {subtitle && (
        <p className="max-w-xl text-base text-muted sm:text-lg">{subtitle}</p>
      )}
    </Reveal>
  );
}
