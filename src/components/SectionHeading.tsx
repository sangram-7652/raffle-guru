import type { ReactNode } from "react";
import Reveal from "./Reveal";

type SectionHeadingProps = {
  eyebrow?: string;
  title: ReactNode;
  subtitle?: string;
  align?: "left" | "center";
  className?: string;
};

export default function SectionHeading({
  eyebrow,
  title,
  subtitle,
  align = "left",
  className = "",
}: SectionHeadingProps) {
  return (
    <Reveal
      className={`flex flex-col gap-3 ${
        align === "center" ? "items-center text-center" : "items-start"
      } ${className}`}
    >
      {eyebrow && (
        <span className="inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.22em] text-pink-600">
          <span className="h-px w-6 bg-pink/60" />
          {eyebrow}
        </span>
      )}
      <h2 className="font-display text-3xl font-extrabold leading-[1.05] tracking-tight text-heading sm:text-4xl md:text-5xl">
        {title}
      </h2>
      {subtitle && (
        <p className="max-w-xl text-base text-muted sm:text-lg">{subtitle}</p>
      )}
    </Reveal>
  );
}
