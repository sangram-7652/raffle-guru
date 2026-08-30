"use client";

import { useEffect, useState } from "react";
import Logo from "../media/Logo";
import Button from "../ui/Button";
import Icon from "../ui/Icon";
import Container from "./Container";
import DesktopNavigation from "./navigation/DesktopNavigation";
import MobileNavigation from "./navigation/MobileNavigation";
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

  return (
    <header
      className={`sticky top-0 z-40 transition-colors duration-300 ${
        scrolled
          ? "border-b border-line bg-white/85 backdrop-blur-xl"
          : "border-b border-transparent bg-transparent"
      }`}
    >
      <Container className="flex items-center justify-between gap-4 py-3">
        <a href="#top" aria-label="RaffleGuru home" className="shrink-0">
          <Logo priority className="h-10 w-auto sm:h-11" />
        </a>

        <DesktopNavigation links={navLinks} />

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

        <div className="flex items-center gap-2 lg:hidden">
          <Button href="#featured" size="sm" className="px-4">
            ENTER
          </Button>
          <button
            type="button"
            onClick={() => setOpen((v) => !v)}
            aria-label={open ? "Close menu" : "Open menu"}
            aria-expanded={open}
            aria-controls="mobile-menu"
            className="grid h-10 w-10 place-items-center rounded-full border border-line bg-panel text-heading focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pink/60"
          >
            <Icon name={open ? "close" : "menu"} />
          </button>
        </div>
      </Container>

      <MobileNavigation
        open={open}
        onClose={() => setOpen(false)}
        links={navLinks}
      />
    </header>
  );
}
