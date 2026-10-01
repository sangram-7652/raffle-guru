"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import Image from "next/image";
import SectionHeading from "../layout/SectionHeading";
import { reviews, reviewSummary } from "@/data/homepageMockData";

const AUTOPLAY_MS = 6000;

function Stars({ rating }: { rating: number }) {
  return (
    <span
      className="inline-flex gap-0.5"
      role="img"
      aria-label={`Rated ${rating} out of 5`}
    >
      {Array.from({ length: 5 }).map((_, i) => (
        <svg
          key={i}
          viewBox="0 0 24 24"
          className={`h-4 w-4 ${i < rating ? "text-gold-700" : "text-track"}`}
          fill="currentColor"
          aria-hidden="true"
        >
          <path d="M12 2.5l2.9 6.06 6.6.83-4.86 4.53 1.24 6.58L12 17.9l-5.88 3.11 1.24-6.58L2.5 9.39l6.6-.83L12 2.5z" />
        </svg>
      ))}
    </span>
  );
}

export default function ReviewsSection() {
  const [active, setActive] = useState(0);
  const [paused, setPaused] = useState(false);
  const count = reviews.length;

  const go = useCallback(
    (next: number) => setActive((next + count) % count),
    [count],
  );

  const prefersReducedMotion = useRef(false);
  useEffect(() => {
    prefersReducedMotion.current = window.matchMedia(
      "(prefers-reduced-motion: reduce)",
    ).matches;
  }, []);

  useEffect(() => {
    if (paused || prefersReducedMotion.current) return;
    const id = window.setInterval(
      () => setActive((a) => (a + 1) % count),
      AUTOPLAY_MS,
    );
    return () => window.clearInterval(id);
  }, [paused, count]);

  return (
    <section
      id="reviews"
      className="relative scroll-mt-24 overflow-hidden bg-paper py-20 lg:py-28"
    >
      <div className="brand-glow absolute inset-0" aria-hidden />

      <div className="relative mx-auto max-w-5xl px-4 sm:px-6">
        <div className="flex flex-col items-center gap-4 text-center">
          <SectionHeading
            eyebrow="Reviews"
            title="What our players say"
            align="center"
            className="mx-auto"
          />
          <p className="inline-flex items-center gap-2 text-sm text-muted">
            <Stars rating={5} />
            <span className="font-display font-bold text-heading">
              {reviewSummary.rating}
            </span>
            from {reviewSummary.count} {reviewSummary.source}
          </p>
        </div>

        <div
          className="relative mt-12"
          onMouseEnter={() => setPaused(true)}
          onMouseLeave={() => setPaused(false)}
          onFocusCapture={() => setPaused(true)}
          onBlurCapture={() => setPaused(false)}
        >
          <div
            className="overflow-hidden"
            aria-live="polite"
            aria-roledescription="carousel"
          >
            <div
              className="flex transition-transform duration-500 ease-[cubic-bezier(0.22,0.68,0.28,1)]"
              style={{ transform: `translateX(-${active * 100}%)` }}
            >
              {reviews.map((review, i) => (
                <figure
                  key={review.id}
                  className="w-full shrink-0 px-1"
                  aria-hidden={i !== active}
                  aria-roledescription="slide"
                  aria-label={`${i + 1} of ${count}`}
                >
                  <div className="card-shadow mx-auto flex max-w-3xl flex-col items-center gap-6 rounded-card border border-line bg-card px-6 py-10 text-center sm:px-12">
                    <Stars rating={review.rating} />
                    <blockquote className="font-display text-lg font-semibold leading-relaxed tracking-tight text-heading sm:text-xl">
                      &ldquo;{review.quote}&rdquo;
                    </blockquote>
                    <figcaption className="flex items-center gap-3">
                      <Image
                        src={review.photo}
                        alt=""
                        width={48}
                        height={48}
                        className="h-12 w-12 rounded-full object-cover"
                      />
                      <span className="text-left">
                        <span className="block font-display text-sm font-bold text-heading">
                          {review.name}
                          <span className="ml-2 font-medium text-muted">
                            {review.location}
                          </span>
                        </span>
                        <span className="block text-xs font-semibold text-gold-700">
                          {review.prize}
                        </span>
                      </span>
                    </figcaption>
                  </div>
                </figure>
              ))}
            </div>
          </div>

          <div className="mt-8 flex items-center justify-center gap-4">
            <button
              type="button"
              onClick={() => go(active - 1)}
              aria-label="Previous review"
              className="grid h-10 w-10 place-items-center rounded-full border border-line bg-card text-heading transition-colors hover:border-gold/60 hover:text-gold-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold-700/70"
            >
              <svg
                viewBox="0 0 24 24"
                className="h-5 w-5"
                fill="none"
                stroke="currentColor"
                strokeWidth="1.7"
                strokeLinecap="round"
                strokeLinejoin="round"
                aria-hidden="true"
              >
                <path d="M15 6l-6 6 6 6" />
              </svg>
            </button>

            <div className="flex gap-2">
              {reviews.map((review, i) => (
                <button
                  key={review.id}
                  type="button"
                  onClick={() => go(i)}
                  aria-label={`Go to review ${i + 1}`}
                  aria-current={i === active}
                  className={`h-2 rounded-full transition-all ${
                    i === active
                      ? "w-6 brand-gradient"
                      : "w-2 bg-track hover:bg-gold-pale"
                  }`}
                />
              ))}
            </div>

            <button
              type="button"
              onClick={() => go(active + 1)}
              aria-label="Next review"
              className="grid h-10 w-10 place-items-center rounded-full border border-line bg-card text-heading transition-colors hover:border-gold/60 hover:text-gold-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold-700/70"
            >
              <svg
                viewBox="0 0 24 24"
                className="h-5 w-5"
                fill="none"
                stroke="currentColor"
                strokeWidth="1.7"
                strokeLinecap="round"
                strokeLinejoin="round"
                aria-hidden="true"
              >
                <path d="M9 6l6 6-6 6" />
              </svg>
            </button>
          </div>
        </div>
      </div>
    </section>
  );
}
