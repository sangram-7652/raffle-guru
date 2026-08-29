import Icon from "./Icon";
import Reveal from "./Reveal";

export default function FinalCTA() {
  return (
    <section className="px-4 py-20 sm:px-6 lg:py-28 xl:px-8">
      <Reveal className="relative mx-auto max-w-7xl overflow-hidden rounded-[2rem] brand-gradient px-6 py-16 text-center sm:px-12 sm:py-20">
        <div className="grid-texture pointer-events-none absolute inset-0 opacity-20 mix-blend-overlay" />
        <div className="pointer-events-none absolute -left-16 -top-16 h-56 w-56 rounded-full bg-white/20 blur-3xl" />
        <div className="pointer-events-none absolute -bottom-20 -right-10 h-64 w-64 rounded-full bg-black/20 blur-3xl" />

        <div className="relative">
          <span className="inline-flex items-center gap-2 rounded-full bg-black/20 px-4 py-1.5 text-xs font-semibold uppercase tracking-[0.2em] text-white">
            <Icon name="spark" className="h-3.5 w-3.5" />
            New draws every week
          </span>
          <h2 className="mx-auto mt-6 max-w-2xl font-display text-4xl font-extrabold leading-[1.02] tracking-tight text-white sm:text-5xl md:text-6xl">
            Ready for your next big win?
          </h2>
          <p className="mx-auto mt-4 max-w-md text-lg text-white/90">
            Your chance is waiting.
          </p>
          <div className="mt-8 flex justify-center">
            <a
              href="#featured"
              className="inline-flex h-14 items-center justify-center gap-2 rounded-full bg-ink px-9 font-display text-base font-bold tracking-tight text-white shadow-[0_16px_40px_-12px_rgba(0,0,0,0.5)] transition-all duration-200 hover:-translate-y-0.5 hover:bg-ink/90 active:scale-[0.98]"
            >
              EXPLORE COMPETITIONS
              <Icon name="arrow" className="h-4 w-4" />
            </a>
          </div>
        </div>
      </Reveal>
    </section>
  );
}
