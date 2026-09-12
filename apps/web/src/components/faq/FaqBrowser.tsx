"use client";

import { useMemo, useState } from "react";
import Accordion from "../ui/Accordion";
import Icon from "../ui/Icon";
import EmptyState from "../ui/EmptyState";
import { faqCategories } from "@/data/faqs";

export default function FaqBrowser() {
  const [active, setActive] = useState<string>(faqCategories[0].id);
  const [query, setQuery] = useState("");

  const q = query.trim().toLowerCase();
  const searching = q.length > 1;

  const results = useMemo(() => {
    if (!searching) {
      const cat = faqCategories.find((c) => c.id === active)!;
      return [{ label: cat.label, items: cat.items }];
    }
    return faqCategories
      .map((c) => ({
        label: c.label,
        items: c.items.filter(
          (it) =>
            it.q.toLowerCase().includes(q) || it.a.toLowerCase().includes(q),
        ),
      }))
      .filter((g) => g.items.length > 0);
  }, [active, q, searching]);

  const total = results.reduce((n, g) => n + g.items.length, 0);

  return (
    <div className="grid gap-8 lg:grid-cols-[15rem_1fr] lg:gap-12">
      <div className="lg:sticky lg:top-24 lg:self-start">
        <label className="relative block">
          <span className="sr-only">Search FAQs</span>
          <Icon
            name="spark"
            className="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted"
          />
          <input
            type="search"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder="Search questions…"
            className="h-11 w-full rounded-full border border-line bg-card pl-10 pr-4 text-sm text-heading placeholder:text-muted/70 focus-visible:border-pink-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pink/40"
          />
        </label>

        <nav
          aria-label="FAQ categories"
          className="mt-4 flex gap-2 overflow-x-auto pb-1 lg:mt-6 lg:flex-col lg:gap-1 lg:overflow-visible"
        >
          {faqCategories.map((c) => {
            const isActive = !searching && c.id === active;
            return (
              <button
                key={c.id}
                type="button"
                onClick={() => {
                  setActive(c.id);
                  setQuery("");
                }}
                aria-current={isActive ? "true" : undefined}
                className={`shrink-0 rounded-full px-4 py-2 text-left text-sm font-medium transition-colors lg:rounded-lg ${
                  isActive
                    ? "bg-heading text-white lg:bg-panel lg:text-heading lg:font-semibold"
                    : "border border-line text-muted hover:text-heading lg:border-transparent lg:hover:bg-panel"
                }`}
              >
                {c.label}
              </button>
            );
          })}
        </nav>
      </div>

      <div>
        {searching && (
          <p className="mb-5 text-sm text-muted" role="status">
            {total} result{total === 1 ? "" : "s"} for &ldquo;{query.trim()}
            &rdquo;
          </p>
        )}

        {total === 0 ? (
          <EmptyState
            icon="spark"
            title="No matching questions"
            description="Try different words, or contact our team and we'll help directly."
          />
        ) : (
          <div className="space-y-8">
            {results.map((group) => (
              <section key={group.label} aria-label={group.label}>
                {searching && (
                  <h2 className="t-eyebrow mb-3 text-pink-600">{group.label}</h2>
                )}
                <Accordion
                  items={group.items.map((it) => ({
                    id: it.id,
                    title: it.q,
                    content: <p>{it.a}</p>,
                  }))}
                />
              </section>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}
