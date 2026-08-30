"use client";

import Icon from "../ui/Icon";
import {
  competitionCategories,
  competitionSorts,
  type CompetitionSort,
} from "@/data/competitions";

type ToolbarProps = {
  query: string;
  onQuery: (v: string) => void;
  category: string;
  onCategory: (v: string) => void;
  sort: CompetitionSort;
  onSort: (v: CompetitionSort) => void;
  resultCount: number;
};

export default function CompetitionToolbar({
  query,
  onQuery,
  category,
  onCategory,
  sort,
  onSort,
  resultCount,
}: ToolbarProps) {
  return (
    <div className="rounded-card border border-line bg-card p-4 card-shadow sm:p-5">
      <div className="flex flex-col gap-3 sm:flex-row">
        <label className="relative flex-1">
          <span className="sr-only">Search competitions</span>
          <Icon
            name="spark"
            className="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted"
          />
          <input
            type="search"
            value={query}
            onChange={(e) => onQuery(e.target.value)}
            placeholder="Search prizes, e.g. BMW, cash, Rolex…"
            className="h-11 w-full rounded-full border border-line bg-paper pl-10 pr-4 text-sm text-heading placeholder:text-muted/70 focus-visible:border-pink-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pink/40"
          />
        </label>
        <label className="relative sm:w-56">
          <span className="sr-only">Sort competitions</span>
          <select
            value={sort}
            onChange={(e) => onSort(e.target.value as CompetitionSort)}
            className="h-11 w-full appearance-none rounded-full border border-line bg-paper pl-4 pr-10 text-sm text-heading focus-visible:border-pink-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pink/40"
          >
            {competitionSorts.map((s) => (
              <option key={s.value} value={s.value}>
                Sort: {s.label}
              </option>
            ))}
          </select>
          <Icon
            name="arrow"
            className="pointer-events-none absolute right-4 top-1/2 h-3.5 w-3.5 -translate-y-1/2 rotate-90 text-muted"
          />
        </label>
      </div>

      <div className="mt-4 flex items-center gap-2 overflow-x-auto pb-1">
        {competitionCategories.map((c) => (
          <button
            key={c}
            type="button"
            onClick={() => onCategory(c)}
            aria-pressed={category === c}
            className={`shrink-0 rounded-full px-3.5 py-1.5 text-xs font-semibold uppercase tracking-wide transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pink/50 ${
              category === c
                ? "brand-gradient text-white"
                : "border border-line text-muted hover:border-pink/50 hover:text-heading"
            }`}
          >
            {c}
          </button>
        ))}
      </div>

      <p className="mt-3 text-xs text-muted" role="status" aria-live="polite">
        {resultCount} competition{resultCount === 1 ? "" : "s"}
      </p>
    </div>
  );
}
