import Link from "next/link";
import { Fragment } from "react";

export type Crumb = { label: string; href?: string };

export default function Breadcrumbs({
  items,
  className = "",
}: {
  items: Crumb[];
  className?: string;
}) {
  return (
    <nav aria-label="Breadcrumb" className={className}>
      <ol className="flex flex-wrap items-center gap-1.5 text-xs text-muted">
        {items.map((item, i) => {
          const last = i === items.length - 1;
          return (
            <Fragment key={item.label}>
              <li>
                {item.href && !last ? (
                  <Link
                    href={item.href}
                    className="transition-colors hover:text-heading focus-visible:outline-none focus-visible:text-heading"
                  >
                    {item.label}
                  </Link>
                ) : (
                  <span
                    className={last ? "font-medium text-heading" : undefined}
                    aria-current={last ? "page" : undefined}
                  >
                    {item.label}
                  </span>
                )}
              </li>
              {!last && (
                <li aria-hidden className="text-line">
                  /
                </li>
              )}
            </Fragment>
          );
        })}
      </ol>
    </nav>
  );
}
