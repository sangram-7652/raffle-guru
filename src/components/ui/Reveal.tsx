"use client";

import { useEffect, useRef, useState, type ReactNode } from "react";

type RevealProps = {
  children: ReactNode;
  /** stagger delay in ms */
  delay?: number;
  className?: string;
  as?: "div" | "li" | "section";
};

/**
 * Lightweight scroll-reveal wrapper. Uses a single IntersectionObserver,
 * respects prefers-reduced-motion (handled in CSS), and never blocks paint.
 */
export default function Reveal({
  children,
  delay = 0,
  className = "",
  as = "div",
}: RevealProps) {
  const ref = useRef<HTMLElement | null>(null);
  const [shown, setShown] = useState(false);

  useEffect(() => {
    const el = ref.current;
    if (!el) return;
    const io = new IntersectionObserver(
      ([entry]) => {
        if (entry.isIntersecting) {
          setShown(true);
          io.disconnect();
        }
      },
      { threshold: 0.12, rootMargin: "0px 0px -60px 0px" },
    );
    io.observe(el);
    return () => io.disconnect();
  }, []);

  const Tag = as;

  return (
    <Tag
      // @ts-expect-error — ref typing across the union of tags is fine at runtime
      ref={ref}
      className={className}
      style={{
        opacity: shown ? 1 : 0,
        transform: shown ? "none" : "translateY(24px)",
        transition:
          "opacity 0.6s cubic-bezier(0.22,0.68,0.28,1), transform 0.6s cubic-bezier(0.22,0.68,0.28,1)",
        transitionDelay: `${delay}ms`,
      }}
    >
      {children}
    </Tag>
  );
}
