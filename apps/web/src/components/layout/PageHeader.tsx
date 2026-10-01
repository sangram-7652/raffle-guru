import type { ReactNode } from "react";
import Container from "./Container";
import Breadcrumbs, { type Crumb } from "./Breadcrumbs";

type PageHeaderProps = {
  eyebrow?: string;
  title: ReactNode;
  lede?: ReactNode;
  breadcrumbs?: Crumb[];
  /** action buttons / controls, right-aligned on desktop */
  actions?: ReactNode;
  /** extra content below the lede (stats, meta row, etc.) */
  children?: ReactNode;
  align?: "left" | "center";
  /** subtle brand wash + grid texture (used for marketing pages) */
  decorated?: boolean;
};

/**
 * Standard page masthead: breadcrumb + eyebrow + H1 + lede, on the site
 * container. Uses the `.t-*` type scale — the H1 is always `.t-h1`.
 */
export default function PageHeader({
  eyebrow,
  title,
  lede,
  breadcrumbs,
  actions,
  children,
  align = "left",
  decorated = false,
}: PageHeaderProps) {
  const centered = align === "center";
  return (
    <header
      className={`relative overflow-hidden border-b border-line bg-paper ${
        decorated ? "brand-glow" : ""
      }`}
    >
      {decorated && (
        <div className="grid-texture pointer-events-none absolute inset-0 opacity-[0.3]" />
      )}
      <Container className="relative z-10 py-10 sm:py-14 lg:py-16">
        {breadcrumbs && breadcrumbs.length > 0 && (
          <Breadcrumbs items={breadcrumbs} className="mb-6" />
        )}
        <div
          className={`flex flex-col gap-6 ${
            actions ? "lg:flex-row lg:items-end lg:justify-between" : ""
          }`}
        >
          <div
            className={`flex flex-col gap-4 ${
              centered ? "mx-auto items-center text-center" : "max-w-2xl"
            }`}
          >
            {eyebrow && (
              <span className="t-eyebrow inline-flex items-center gap-2 text-gold-700">
                <span className="h-px w-6 bg-gold/60" />
                {eyebrow}
              </span>
            )}
            <h1 className="t-h1 text-heading">{title}</h1>
            {lede && (
              <p className="t-body-lg text-muted">{lede}</p>
            )}
          </div>
          {actions && <div className="flex shrink-0 flex-wrap gap-3">{actions}</div>}
        </div>
        {children && <div className="mt-8">{children}</div>}
      </Container>
    </header>
  );
}
