"use client";

import { useId, useState, type ReactNode } from "react";

export type AccordionItem = {
  id: string;
  title: ReactNode;
  content: ReactNode;
};

type AccordionProps = {
  items: AccordionItem[];
  /** allow more than one panel open at once */
  multiple?: boolean;
  /** ids open on first render */
  defaultOpen?: string[];
  /** heading level for each trigger (document outline) */
  headingLevel?: 2 | 3 | 4;
  className?: string;
};

/**
 * Accessible disclosure group. Each trigger is a real <button> inside a
 * heading, wired to its panel with aria-expanded / aria-controls. Panels
 * use a grid-rows transition so height animates without JS measurement and
 * collapses instantly under prefers-reduced-motion (global rule).
 */
export default function Accordion({
  items,
  multiple = false,
  defaultOpen = [],
  headingLevel = 3,
  className = "",
}: AccordionProps) {
  const base = useId();
  const [open, setOpen] = useState<Set<string>>(new Set(defaultOpen));
  const Heading = `h${headingLevel}` as "h2" | "h3" | "h4";

  const toggle = (id: string) => {
    setOpen((prev) => {
      const next = new Set(multiple ? prev : []);
      if (prev.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  return (
    <div className={`space-y-3 ${className}`}>
      {items.map((item) => {
        const isOpen = open.has(item.id);
        const btnId = `${base}-${item.id}-btn`;
        const panelId = `${base}-${item.id}-panel`;
        return (
          <div
            key={item.id}
            className={`overflow-hidden rounded-2xl border bg-card transition-colors ${
              isOpen ? "border-gold/30" : "border-line"
            }`}
          >
            <Heading className="m-0">
              <button
                type="button"
                id={btnId}
                aria-expanded={isOpen}
                aria-controls={panelId}
                onClick={() => toggle(item.id)}
                className="flex w-full items-center justify-between gap-4 px-5 py-4 text-left font-display text-base font-bold tracking-tight text-heading focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-gold-700/50"
              >
                {item.title}
                <span
                  aria-hidden
                  className={`grid h-6 w-6 shrink-0 place-items-center rounded-full border border-line text-gold-700 transition-transform duration-300 ${
                    isOpen ? "rotate-45" : ""
                  }`}
                >
                  +
                </span>
              </button>
            </Heading>
            <div
              id={panelId}
              role="region"
              aria-labelledby={btnId}
              className="grid transition-[grid-template-rows] duration-300"
              style={{ gridTemplateRows: isOpen ? "1fr" : "0fr" }}
            >
              <div className="min-h-0 overflow-hidden" inert={!isOpen}>
                <div className="px-5 pb-5 pt-0 text-sm leading-relaxed text-muted">
                  {item.content}
                </div>
              </div>
            </div>
          </div>
        );
      })}
    </div>
  );
}
