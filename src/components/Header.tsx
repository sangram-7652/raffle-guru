"use client";

import { useEffect, useState } from "react";
import Logo from "./Logo";
import Button from "./Button";
import Icon from "./Icon";
import { navLinks } from "@/data/homepageMockData";

export default function Header() {
  const [open, setOpen] = useState(false);
  const [scrolled, setScrolled] = useState(false);

  useEffect(() => {
    const onScroll = () => setScrolled(window.scrollY > 12);
    onScroll();
    window.addEventListener("scroll", onScroll, { passive: true });
    return () => window.removeEventListener("scroll", onScroll);
  }, []);

  useEffect(() => {
    document.body.style.overflow = open ? "hidden" : "";
    return () => {
      document.body.style.overflow = "";
    };
  }, [open]);

  return (
    <header
      className={`sticky top-0 z-40 transition-colors duration-300 ${
        scrolled
          ? "border-b border-line bg-white/85 backdrop-blur-xl"
          : "border-b border-transparent bg-transparent"
      }`}
    >
      <div className="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-3 sm:px-6 lg:px-8">
        <a href="#top" aria-label="RaffleGuru home" className="shrink-0">
          <Logo priority className="h-10 w-auto sm:h-11" />
        </a>

        <nav className="hidden items-center gap-8 lg:flex">
          {navLinks.map((link) => (
            <a
              key={link.label}
              href={link.href}
              className="text-sm font-medium text-muted transition-colors hover:text-heading"
            >
              {link.label}
            </a>
          ))}
        </nav>

        <div className="hidden items-center gap-3 lg:flex">
          <a
            href="#"
            className="text-sm font-semibold text-heading transition-colors hover:text-pink-600"
          >
            Login
          </a>
          <Button href="#featured" size="sm">
            ENTER NOW
          </Button>
        </div>

        {/* Mobile */}
        <div className="flex items-center gap-2 lg:hidden">
          <Button href="#featured" size="sm" className="px-4">
            ENTER
          </Button>
          <button
            type="button"
            onClick={() => setOpen((v) => !v)}
            aria-label={open ? "Close menu" : "Open menu"}
            aria-expanded={open}
            className="grid h-10 w-10 place-items-center rounded-full border border-line bg-panel text-heading"
          >
            <Icon name={open ? "close" : "menu"} />
          </button>
        </div>
      </div>

      {/* Mobile drawer */}
      {open && (
        <div className="lg:hidden">
          <div
            className="fixed inset-0 top-[64px] z-30 bg-white/95 backdrop-blur-xl"
            style={{ animation: "var(--animate-fade)" }}
          >
            <nav className="mx-auto flex max-w-7xl flex-col gap-1 px-4 py-6">
              {navLinks.map((link, i) => (
                <a
                  key={link.label}
                  href={link.href}
                  onClick={() => setOpen(false)}
                  className="border-b border-line py-4 font-display text-2xl font-bold tracking-tight text-heading"
                  style={{
                    animation: "var(--animate-rise)",
                    animationDelay: `${i * 45}ms`,
                  }}
                >
                  {link.label}
                </a>
              ))}
              <a
                href="#"
                onClick={() => setOpen(false)}
                className="py-4 font-display text-2xl font-bold tracking-tight text-muted"
              >
                Login
              </a>
              <Button
                href="#featured"
                size="lg"
                className="mt-4 w-full"
                onClick={() => setOpen(false)}
              >
                ENTER NOW
              </Button>
            </nav>
          </div>
        </div>
      )}
    </header>
  );
}
