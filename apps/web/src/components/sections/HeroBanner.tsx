"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import Image from "next/image";
import Icon from "../ui/Icon";
import { heroBannerSlides } from "@/data/homepageMockData";

const AUTOPLAY_MS = 5500;

export default function HeroBanner() {
  const [active, setActive] = useState(0);
  const [paused, setPaused] = useState(false);
  const count = heroBannerSlides.length;
  const reducedMotion = useRef(false);

  const go = useCallback(
    (next: number) => setActive((next + count) % count),
    [count],
  );

  useEffect(() => {
    reducedMotion.current = window.matchMedia(
      "(prefers-reduced-motion: reduce)",
    ).matches;
  }, []);

  useEffect(() => {
    if (paused || reducedMotion.current) return;
    const id = window.setInterval(
      () => setActive((a) => (a + 1) % count),
      AUTOPLAY_MS,
    );
    return () => window.clearInterval(id);
  }, [paused, count]);

  return (
    <div
      className="card-shadow-lg relative overflow-hidden rounded-panel border border-line bg-ink"
      role="region"
      aria-roledescription="carousel"
      aria-label="Featured competitions"
      onMouseEnter={() => setPaused(true)}
      onMouseLeave={() => setPaused(false)}
      onFocusCapture={() => setPaused(true)}
      onBlurCapture={() => setPaused(false)}
    >
      <div className="relative min-h-[26rem] w-full sm:min-h-0 sm:aspect-[21/7]">

        {heroBannerSlides.map((slide, i) => (
          <div
            key={slide.id}
            className="absolute inset-0 transition-opacity duration-700 ease-out"
            style={{ opacity: i === active ? 1 : 0 }}
            aria-hidden={i !== active}
          >
            <Image
              src={slide.image}
              alt=""
              fill
              priority={i === 0}
              sizes="(max-width: 1280px) 100vw, 1200px"
              className={`object-cover ${slide.imagePositionClassName ?? ""}`}
            />
            <div className="absolute inset-0 bg-gradient-to-r from-ink via-ink/70 to-ink/10" />

            <div className="relative flex h-full flex-col justify-center gap-3 px-6 pb-16 pt-8 sm:px-10 sm:py-6 lg:px-14">
              <span className="text-[11px] font-semibold uppercase tracking-[0.22em] text-gold-pale">
                {slide.eyebrow}
              </span>
              <h2 className="max-w-xl font-display text-2xl font-extrabold leading-tight tracking-tight text-white sm:text-3xl lg:text-4xl">
                {slide.title}
              </h2>
              <p className="max-w-md text-sm text-white/75 sm:text-base">
                {slide.subtitle}
              </p>
              <a
                href={slide.ctaHref}
                tabIndex={i === active ? 0 : -1}
                className="mt-2 inline-flex w-fit items-center gap-2 rounded-full brand-gradient px-5 py-2.5 font-display text-sm font-bold tracking-tight text-ink transition-transform hover:-translate-y-0.5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold/70 focus-visible:ring-offset-2 focus-visible:ring-offset-ink"
              >
                {slide.ctaLabel}
                <Icon name="arrow" className="h-4 w-4" />
              </a>
            </div>
          </div>
        ))}
      </div>

      {/* Controls */}
      <button
        type="button"
        onClick={() => go(active - 1)}
        aria-label="Previous slide"
        className="absolute left-3 top-1/2 grid h-9 w-9 -translate-y-1/2 place-items-center rounded-full border border-white/25 bg-ink/50 text-white backdrop-blur transition-colors hover:bg-ink/80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold/70"
      >
        <Icon name="arrow" className="h-4 w-4 rotate-180" />
      </button>
      <button
        type="button"
        onClick={() => go(active + 1)}
        aria-label="Next slide"
        className="absolute right-3 top-1/2 grid h-9 w-9 -translate-y-1/2 place-items-center rounded-full border border-white/25 bg-ink/50 text-white backdrop-blur transition-colors hover:bg-ink/80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold/70"
      >
        <Icon name="arrow" className="h-4 w-4" />
      </button>

      <div className="absolute bottom-4 left-1/2 flex -translate-x-1/2 gap-2">
        {heroBannerSlides.map((slide, i) => (
          <button
            key={slide.id}
            type="button"
            onClick={() => go(i)}
            aria-label={`Go to slide ${i + 1}`}
            aria-current={i === active}
            className={`h-2 rounded-full transition-all ${
              i === active ? "w-6 brand-gradient" : "w-2 bg-white/40 hover:bg-white/70"
            }`}
          />
        ))}
      </div>
    </div>
  );
}
