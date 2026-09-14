"use client";

import { useEffect, useState } from "react";
import Icon from "../ui/Icon";
import { competitionAvailability } from "@/lib/competitionAvailability";
import { gbp } from "@/lib/format";
import type { Competition } from "@/data/competitions";

/**
 * Mobile-only sticky bottom bar. Appears once the main entry panel scrolls
 * out of view; tapping it scrolls back to the entry selector.
 */
export default function StickyEnterBar({
  competition,
}: {
  competition: Competition;
}) {
  const [show, setShow] = useState(false);
  const availability = competitionAvailability(competition.status);
  const soldOut = !availability.purchasable || competition.soldPct >= 100;

  useEffect(() => {
    const target = document.getElementById("enter");
    if (!target) return;
    const io = new IntersectionObserver(
      ([entry]) => setShow(!entry.isIntersecting),
      { rootMargin: "-120px 0px 0px 0px" },
    );
    io.observe(target);
    return () => io.disconnect();
  }, []);

  return (
    <div
      className={`fixed inset-x-0 bottom-0 z-40 border-t border-line bg-white/95 backdrop-blur-xl transition-transform duration-300 lg:hidden ${
        show ? "translate-y-0" : "translate-y-full"
      }`}
    >
      <div className="mx-auto flex max-w-7xl items-center justify-between gap-3 px-4 py-3">
        <div className="min-w-0">
          <p className="truncate font-display text-sm font-bold text-heading">
            {competition.title}
          </p>
          <p className="text-xs text-muted">
            {soldOut ? availability.label : `From ${gbp(competition.entryPrice)} per entry`}
          </p>
        </div>
        <a
          href="#enter"
          className={`inline-flex h-11 shrink-0 items-center gap-2 rounded-full px-6 font-display text-sm font-bold tracking-tight text-white transition-transform active:scale-[0.98] ${
            soldOut ? "bg-heading/60" : "brand-gradient hover:-translate-y-0.5"
          }`}
          aria-disabled={soldOut || undefined}
        >
          {soldOut ? availability.label : "Enter now"}
          <Icon name="arrow" className="h-4 w-4" />
        </a>
      </div>
    </div>
  );
}
