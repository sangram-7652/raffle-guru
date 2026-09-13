"use client";

import { useCallback, useEffect, useState } from "react";
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
import { adaptCompetition } from "@/lib/competitions/adapter";
import { listCompetitions } from "@/lib/competitions/client";
import type { CompetitionSortKey } from "@/lib/competitions/types";
import { routes } from "@/lib/routes";

const PER_PAGE = 6;

const sortParams: Record<
  CompetitionSort,
  { sort: CompetitionSortKey; direction?: "asc" | "desc" }
> = {
  "ending-soon": { sort: "ending_soon" },
  newest: { sort: "newest" },
  "price-low": { sort: "price", direction: "asc" },
  "price-high": { sort: "price", direction: "desc" },
};

type FetchStatus = "loading" | "success" | "error";

export default function CompetitionListing({
  initialCategory = "All",
}: {
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
  const [debouncedQuery, setDebouncedQuery] = useState("");
  const [category, setCategory] = useState(safeInitial);
  const [sort, setSort] = useState<CompetitionSort>("ending-soon");
  const [page, setPage] = useState(1);

  // debounce free-text search so we don't fire a request per keystroke
  useEffect(() => {
    const t = setTimeout(() => setDebouncedQuery(query), 300);
    return () => clearTimeout(t);
  }, [query]);

  // keep ?category= in the URL so links + refresh + share work
  useEffect(() => {
    const qs = category === "All" ? "" : `?category=${encodeURIComponent(category)}`;
    router.replace(`${pathname}${qs}`, { scroll: false });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [category]);

  const resetPage = useCallback(() => setPage(1), []);

  const isDefaultView =
    category === "All" && debouncedQuery.trim() === "" && sort === "ending-soon";

  // -- main (filterable/paginated) list --
  const [items, setItems] = useState<Competition[]>([]);
  const [resultCount, setResultCount] = useState(0);
  const [pageCount, setPageCount] = useState(1);
  const [listError, setListError] = useState(false);
  const [retryTick, setRetryTick] = useState(0);
  // "loading" is derived by comparing keys, not toggled with a synchronous
  // setState at the top of the effect (that pattern causes an extra
  // cascading render — see react-hooks/set-state-in-effect). The key only
  // updates from inside the .then()/.catch() callbacks below.
  const listRequestKey = JSON.stringify([debouncedQuery, category, sort, page, retryTick]);
  const [listResolvedKey, setListResolvedKey] = useState<string | null>(null);
  const listStatus: FetchStatus = listError
    ? "error"
    : listRequestKey !== listResolvedKey
      ? "loading"
      : "success";

  useEffect(() => {
    let cancelled = false;
    const { sort: sortKey, direction } = sortParams[sort];

    listCompetitions({
      search: debouncedQuery.trim() || undefined,
      category: category === "All" ? undefined : category,
      sort: sortKey,
      direction,
      per_page: PER_PAGE,
      page,
    })
      .then((response) => {
        if (cancelled) return;
        setItems(response.data.map(adaptCompetition));
        setResultCount(response.meta.total);
        setPageCount(Math.max(1, response.meta.last_page));
        setListError(false);
        setListResolvedKey(listRequestKey);
      })
      .catch(() => {
        if (cancelled) return;
        setListError(true);
        setListResolvedKey(listRequestKey);
      });

    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [listRequestKey]);

  // -- featured / ending-soon sections (default view only) --
  const [featured, setFeatured] = useState<Competition[]>([]);
  const [endingSoon, setEndingSoon] = useState<Competition[]>([]);
  const [sectionsError, setSectionsError] = useState(false);
  const [sectionsResolved, setSectionsResolved] = useState(false);
  const sectionsStatus: FetchStatus = sectionsError
    ? "error"
    : sectionsResolved
      ? "success"
      : "loading";

  useEffect(() => {
    if (!isDefaultView) return;
    let cancelled = false;

    Promise.all([
      listCompetitions({ featured: true, per_page: 3, sort: "ending_soon" }),
      listCompetitions({ per_page: 3, sort: "ending_soon" }),
    ])
      .then(([featuredRes, endingSoonRes]) => {
        if (cancelled) return;
        setFeatured(featuredRes.data.map(adaptCompetition));
        setEndingSoon(endingSoonRes.data.map(adaptCompetition));
        setSectionsError(false);
        setSectionsResolved(true);
      })
      .catch(() => {
        if (cancelled) return;
        setSectionsError(true);
        setSectionsResolved(true);
      });

    return () => {
      cancelled = true;
    };
  }, [isDefaultView]);

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
        resultCount={resultCount}
      />

      {isDefaultView && sectionsStatus !== "error" && (featured.length > 0 || sectionsStatus === "loading") && (
        <section aria-label="Featured competitions">
          <SectionHeading
            as="h2"
            eyebrow="The big prizes"
            title="Featured competitions"
          />
          <div className="mt-8">
            {sectionsStatus === "loading" ? (
              <ListSkeleton count={3} />
            ) : (
              <CompetitionGrid competitions={featured} columns={3} reveal={false} />
            )}
          </div>
        </section>
      )}

      {isDefaultView && sectionsStatus !== "error" && (endingSoon.length > 0 || sectionsStatus === "loading") && (
        <section aria-label="Ending soon">
          <SectionHeading as="h2" eyebrow="Closing next" title="Ending soon" />
          <div className="mt-8">
            {sectionsStatus === "loading" ? (
              <ListSkeleton count={3} />
            ) : (
              <CompetitionGrid competitions={endingSoon} columns={3} reveal={false} />
            )}
          </div>
        </section>
      )}

      <section id="all-competitions" className="scroll-mt-24" aria-label="All competitions">
        <div className="flex flex-wrap items-end justify-between gap-4">
          <SectionHeading
            as="h2"
            eyebrow="Every live draw"
            title={
              isDefaultView
                ? "All competitions"
                : `${resultCount} result${resultCount === 1 ? "" : "s"}`
            }
          />
        </div>

        <div className="mt-8">
          {listStatus === "loading" ? (
            <ListSkeleton count={PER_PAGE} />
          ) : listStatus === "error" ? (
            <EmptyState
              icon="ticket"
              title="Couldn't load competitions"
              description="Something went wrong reaching the server. Please try again."
              action={
                <Button variant="secondary" onClick={() => setRetryTick((t) => t + 1)}>
                  Retry
                </Button>
              }
            />
          ) : items.length === 0 ? (
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
              <CompetitionGrid competitions={items} columns={3} reveal={false} />
              <Pagination
                className="mt-12"
                page={Math.min(page, pageCount)}
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
        All competitions shown here are real API data — no payments are
        processed and entries aren&apos;t recorded yet.{" "}
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

function ListSkeleton({ count }: { count: number }) {
  return (
    <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
      {Array.from({ length: count }).map((_, i) => (
        <div
          key={i}
          className="h-80 animate-pulse rounded-card border border-line bg-mist"
          aria-hidden
        />
      ))}
    </div>
  );
}
