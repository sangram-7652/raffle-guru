"use client";

import { useMemo, useState } from "react";
import WinnerCard from "./WinnerCard";
import Button from "../ui/Button";
import EmptyState from "../ui/EmptyState";
import type { Winner } from "@/data/winners";

const FILTERS = [
  { id: "all", label: "All wins", match: () => true },
  {
    id: "cars",
    label: "Cars & bikes",
    match: (w: Winner) => /bmw|range rover|ducati|audi|porsche|car/i.test(w.prize),
  },
  {
    id: "cash",
    label: "Cash",
    match: (w: Winner) => /cash|£[\d,]+$/i.test(w.prize),
  },
  {
    id: "luxury",
    label: "Watches & luxury",
    match: (w: Winner) => /rolex|watch|omega/i.test(w.prize),
  },
  {
    id: "other",
    label: "Tech & trips",
    match: (w: Winner) => /tech|gaming|escape|holiday|bundle/i.test(w.prize),
  },
] as const;

const PAGE = 8;

export default function WinnersFeed({ winners }: { winners: Winner[] }) {
  const [filter, setFilter] = useState<string>("all");
  const [shown, setShown] = useState(PAGE);

  const filtered = useMemo(() => {
    const f = FILTERS.find((x) => x.id === filter) ?? FILTERS[0];
    return winners.filter(f.match);
  }, [winners, filter]);

  const visible = filtered.slice(0, shown);

  return (
    <div>
      <div
        role="tablist"
        aria-label="Filter winners"
        className="flex flex-wrap gap-2"
      >
        {FILTERS.map((f) => (
          <button
            key={f.id}
            type="button"
            role="tab"
            aria-selected={filter === f.id}
            onClick={() => {
              setFilter(f.id);
              setShown(PAGE);
            }}
            className={`rounded-full px-4 py-2 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold/50 ${
              filter === f.id
                ? "brand-gradient text-ink"
                : "border border-line bg-card text-muted hover:text-heading"
            }`}
          >
            {f.label}
          </button>
        ))}
      </div>

      {filtered.length === 0 ? (
        <EmptyState
          className="mt-10"
          icon="trophy"
          title="No winners in this category yet"
          description="Check back after the next round of draws."
        />
      ) : (
        <>
          <div className="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            {visible.map((w) => (
              <WinnerCard
                key={w.id}
                winner={{
                  name: w.name,
                  location: w.location,
                  prize: w.prize,
                  competition: w.competition,
                  ticket: w.ticket,
                  photo: w.photo,
                  drawDate: w.drawDate,
                  quote: w.quote,
                }}
              />
            ))}
          </div>

          {shown < filtered.length && (
            <div className="mt-10 flex justify-center">
              <Button
                variant="secondary"
                onClick={() => setShown((s) => s + PAGE)}
              >
                Show more winners
              </Button>
            </div>
          )}
        </>
      )}
    </div>
  );
}
