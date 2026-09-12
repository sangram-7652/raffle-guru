"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { usePathname, useRouter } from "next/navigation";
import CompetitionGrid from "./CompetitionGrid";
import CompetitionToolbar from "./CompetitionToolbar";
import Button from "../ui/Button";
import EmptyState from "../ui/EmptyState";
import Pagination from "../ui/Pagination";
import { SectionHeading } from "@/components/layout";
import {
  competitionCategories,
  type Competition,
  type CompetitionSort,
} from "@/data/competitions";
import { routes } from "@/lib/routes";

const PER_PAGE = 6;

const sorters: Record<
  CompetitionSort,
  (a: Competition, b: Competition) => number
> = {
  "ending-soon": (a, b) => +new Date(a.drawDate) - +new Date(b.drawDate),
  "almost-gone": (a, b) => b.soldPct - a.soldPct,
  "price-low": (a, b) => a.entryPrice - b.entryPrice,
  "price-high": (a, b) => b.entryPrice - a.entryPrice,
  newest: (a, b) => +new Date(b.drawDate) - +new Date(a.drawDate),
};

export default function CompetitionListing({
  competitions,
  initialCategory = "All",
}: {
  competitions: Competition[];
  initialCategory?: string;
}) {
  const router = useRouter();
  const pathname = usePathname();

  const safeInitial = (
    competitionCategories as readonly string[]
  ).includes(initialCategory)
    ? initialCategory
    : "All";

  const [query, setQuery] = useState("");
  const [category, setCategory] = useState(safeInitial);
  const [sort, setSort] = useState<CompetitionSort>("ending-soon");
  const [page, setPage] = useState(1);

  // keep ?category= in the URL so links + refresh + share work
  useEffect(() => {
    const qs = category === "All" ? "" : `?category=${encodeURIComponent(category)}`;
    router.replace(`${pathname}${qs}`, { scroll: false });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [category]);

  const resetPage = useCallback(() => setPage(1), []);

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    return competitions
      .filter((c) => (category === "All" ? true : c.category === category))
      .filter((c) =>
        q
          ? `${c.title} ${c.prize} ${c.blurb} ${c.category}`
              .toLowerCase()
              .includes(q)
          : true,
      )
      .slice()
      .sort(sorters[sort]);
  }, [competitions, category, query, sort]);

  const isDefaultView =
    category === "All" && query.trim() === "" && sort === "ending-soon";

  const featured = useMemo(
    () => competitions.filter((c) => c.featured).slice(0, 3),
    [competitions],
  );
  const endingSoon = useMemo(
    () =>
      competitions
        .filter((c) => c.status !== "sold-out")
        .slice()
        .sort(sorters["ending-soon"])
        .slice(0, 3),
    [competitions],
  );

  const pageCount = Math.max(1, Math.ceil(filtered.length / PER_PAGE));
  const safePage = Math.min(page, pageCount);
  const pageItems = filtered.slice(
    (safePage - 1) * PER_PAGE,
    safePage * PER_PAGE,
  );

  return (
    <div className="space-y-14">
      <CompetitionToolbar
        query={query}
        onQuery={(v) => {
          setQuery(v);
          resetPage();
        }}
        category={category}
        onCategory={(v) => {
          setCategory(v);
          resetPage();
        }}
        sort={sort}
        onSort={(v) => {
          setSort(v);
          resetPage();
        }}
        resultCount={filtered.length}
      />

      {isDefaultView && (
        <>
          <section aria-label="Featured competitions">
            <SectionHeading
              as="h2"
              eyebrow="The big prizes"
              title="Featured competitions"
            />
            <div className="mt-8">
              <CompetitionGrid
                competitions={featured}
                columns={3}
                reveal={false}
              />
            </div>
          </section>

          <section aria-label="Ending soon">
            <SectionHeading as="h2" eyebrow="Closing next" title="Ending soon" />
            <div className="mt-8">
              <CompetitionGrid
                competitions={endingSoon}
                columns={3}
                reveal={false}
              />
            </div>
          </section>
        </>
      )}

      <section id="all-competitions" className="scroll-mt-24" aria-label="All competitions">
        <div className="flex flex-wrap items-end justify-between gap-4">
          <SectionHeading
            as="h2"
            eyebrow="Every live draw"
            title={
              isDefaultView
                ? "All competitions"
                : `${filtered.length} result${filtered.length === 1 ? "" : "s"}`
            }
          />
        </div>

        <div className="mt-8">
          {filtered.length === 0 ? (
            <EmptyState
              icon="ticket"
              title="No competitions match that"
              description="Try a different category or clear your search — new competitions are added every week."
              action={
                <Button
                  variant="secondary"
                  onClick={() => {
                    setQuery("");
                    setCategory("All");
                    setSort("ending-soon");
                    resetPage();
                  }}
                >
                  Clear filters
                </Button>
              }
            />
          ) : (
            <>
              <CompetitionGrid
                competitions={pageItems}
                columns={3}
                reveal={false}
              />
              <Pagination
                className="mt-12"
                page={safePage}
                pageCount={pageCount}
                onChange={(p) => {
                  setPage(p);
                  document
                    .getElementById("all-competitions")
                    ?.scrollIntoView({ behavior: "smooth", block: "start" });
                }}
              />
            </>
          )}
        </div>
      </section>

      <p className="rounded-2xl border border-line bg-mist p-4 text-center text-xs text-muted">
        Prototype — all competitions, prices and draw dates shown are sample
        data. No entries are processed.{" "}
        <a
          href={routes.competitionRules}
          className="font-medium text-pink-700 underline underline-offset-2"
        >
          Competition Rules
        </a>
      </p>
    </div>
  );
}
