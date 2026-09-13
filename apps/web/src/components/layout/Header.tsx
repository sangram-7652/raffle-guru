"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import Logo from "../media/Logo";
import Button from "../ui/Button";
import Icon from "../ui/Icon";
import Container from "./Container";
import DesktopNavigation from "./navigation/DesktopNavigation";
import MobileNavigation from "./navigation/MobileNavigation";
import { useCart } from "../cart/CartContext";
import { useAuth } from "@/lib/auth/AuthContext";
import { mainNav } from "@/data/site";
import { routes } from "@/lib/routes";

function CartLink({ className = "" }: { className?: string }) {
  const { count, hydrated } = useCart();
  return (
    <Link
      href={routes.cart}
      aria-label={`Basket${hydrated && count ? `, ${count} entr${count === 1 ? "y" : "ies"}` : ""}`}
      className={`relative grid h-10 w-10 place-items-center rounded-full border border-line bg-panel text-heading transition-colors hover:border-pink/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pink/60 ${className}`}
    >
      <Icon name="ticket" className="h-[18px] w-[18px]" />
      {hydrated && count > 0 && (
        <span className="absolute -right-1 -top-1 grid h-4 min-w-4 place-items-center rounded-full brand-gradient px-1 text-[10px] font-bold text-white">
          {count > 99 ? "99+" : count}
        </span>
      )}
    </Link>
  );
}

export default function Header() {
  const [open, setOpen] = useState(false);
  const [scrolled, setScrolled] = useState(false);
  const { status, user } = useAuth();

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
        <Link
          href={routes.home}
          aria-label="RaffleGuru home"
          className="shrink-0 rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pink/60"
        >
          <Logo priority className="h-10 w-auto sm:h-11" />
        </Link>

        <DesktopNavigation links={mainNav} />

        <div className="hidden items-center gap-3 lg:flex">
          {status === "authenticated" ? (
            <Link
              href={routes.account}
              className="text-sm font-semibold text-heading transition-colors hover:text-pink-600"
            >
              {user?.name.split(" ")[0]}
            </Link>
          ) : (
            <Link
              href={routes.login}
              className="text-sm font-semibold text-heading transition-colors hover:text-pink-600"
            >
              Login
            </Link>
          )}
          <CartLink />
          <Button href={routes.competitions} size="sm">
            ENTER NOW
          </Button>
        </div>

        <div className="flex items-center gap-2 lg:hidden">
          <CartLink />
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
        links={mainNav}
      />
    </header>
  );
}
