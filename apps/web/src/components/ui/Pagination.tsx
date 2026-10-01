"use client";

import Icon from "./Icon";

type PaginationProps = {
  page: number;
  pageCount: number;
  onChange: (page: number) => void;
  className?: string;
};

function pageList(page: number, count: number): (number | "…")[] {
  if (count <= 7)
    return Array.from({ length: count }, (_, i) => i + 1);
  const out: (number | "…")[] = [1];
  const from = Math.max(2, page - 1);
  const to = Math.min(count - 1, page + 1);
  if (from > 2) out.push("…");
  for (let i = from; i <= to; i++) out.push(i);
  if (to < count - 1) out.push("…");
  out.push(count);
  return out;
}

export default function Pagination({
  page,
  pageCount,
  onChange,
  className = "",
}: PaginationProps) {
  if (pageCount <= 1) return null;

  return (
    <nav
      aria-label="Pagination"
      className={`flex items-center justify-center gap-1.5 ${className}`}
    >
      <button
        type="button"
        onClick={() => onChange(page - 1)}
        disabled={page <= 1}
        aria-label="Previous page"
        className="grid h-10 w-10 place-items-center rounded-full border border-line bg-card text-heading transition-colors hover:border-gold/60 disabled:opacity-30 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold-700/50"
      >
        <Icon name="arrow" className="h-4 w-4 rotate-180" />
      </button>

      {pageList(page, pageCount).map((p, i) =>
        p === "…" ? (
          <span
            key={`gap-${i}`}
            aria-hidden
            className="px-1 text-sm text-muted"
          >
            …
          </span>
        ) : (
          <button
            key={p}
            type="button"
            onClick={() => onChange(p)}
            aria-current={p === page ? "page" : undefined}
            className={`grid h-10 min-w-10 place-items-center rounded-full px-3 font-display text-sm font-bold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold-700/50 ${
              p === page
                ? "brand-gradient text-ink"
                : "border border-line bg-card text-heading hover:border-gold/60"
            }`}
          >
            {p}
          </button>
        ),
      )}

      <button
        type="button"
        onClick={() => onChange(page + 1)}
        disabled={page >= pageCount}
        aria-label="Next page"
        className="grid h-10 w-10 place-items-center rounded-full border border-line bg-card text-heading transition-colors hover:border-gold/60 disabled:opacity-30 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold-700/50"
      >
        <Icon name="arrow" className="h-4 w-4" />
      </button>
    </nav>
  );
}
