import Link from "next/link";
import Icon from "../ui/Icon";
import Reveal from "../ui/Reveal";
import { routes } from "@/lib/routes";

export default function FinalCTA() {
  return (
    <section className="px-4 py-20 sm:px-6 lg:py-28 xl:px-8">
      <Reveal className="relative mx-auto max-w-7xl overflow-hidden rounded-hero brand-gradient px-6 py-16 text-center sm:px-12 sm:py-20">
        <div className="grid-texture pointer-events-none absolute inset-0 opacity-20 mix-blend-overlay" />
        <div className="pointer-events-none absolute -left-16 -top-16 h-56 w-56 rounded-full bg-gold-pale/30 blur-3xl" />
        <div className="pointer-events-none absolute -bottom-20 -right-10 h-64 w-64 rounded-full bg-black/25 blur-3xl" />

        <div className="relative">
          <span className="inline-flex items-center gap-2 rounded-full bg-ink/15 px-4 py-1.5 text-xs font-semibold uppercase tracking-[0.2em] text-ink">
            <Icon name="spark" className="h-3.5 w-3.5" />
            New draws every week
          </span>
          <h2 className="t-h1 mx-auto mt-6 max-w-2xl text-ink">
            Ready to make your move?
          </h2>
          <p className="mx-auto mt-4 max-w-md text-lg text-ink/80">
            Your next big win could be closer than you think.
          </p>
          <div className="mt-8 flex justify-center">
            <Link
              href={routes.competitions}
              className="inline-flex h-14 items-center justify-center gap-2 rounded-full bg-ink px-9 font-display text-base font-bold tracking-tight text-gold shadow-[var(--shadow-ink)] transition-all duration-200 hover:-translate-y-0.5 hover:bg-ink/90 active:scale-[0.98] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ink/70"
            >
              EXPLORE COMPETITIONS
              <Icon name="arrow" className="h-4 w-4" />
            </Link>
          </div>
        </div>
      </Reveal>
    </section>
  );
}
