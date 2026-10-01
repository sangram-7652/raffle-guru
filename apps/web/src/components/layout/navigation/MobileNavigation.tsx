"use client";

import { useEffect } from "react";
import { usePathname } from "next/navigation";
import Button from "../../ui/Button";
import Icon from "../../ui/Icon";
import NavigationItem from "./NavigationItem";
import { routes } from "@/lib/routes";
import type { NavLink } from "@/data/site";

type MobileNavigationProps = {
  open: boolean;
  onClose: () => void;
  links: NavLink[];
};

/**
 * Full-screen mobile menu — deliberately its own layout (large stacked
 * targets, staggered entrance, body scroll lock, Escape to close), not a
 * shrunk copy of the desktop bar.
 */
export default function MobileNavigation({
  open,
  onClose,
  links,
}: MobileNavigationProps) {
  const pathname = usePathname();

  useEffect(() => {
    document.body.style.overflow = open ? "hidden" : "";
    return () => {
      document.body.style.overflow = "";
    };
  }, [open]);

  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => e.key === "Escape" && onClose();
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [open, onClose]);

  if (!open) return null;

  return (
    <div className="lg:hidden">
      <div
        id="mobile-menu"
        className="fixed inset-0 top-[73px] z-30 bg-paper/97 backdrop-blur-xl"
        style={{ animation: "var(--animate-fade)" }}
      >
        <nav
          aria-label="Mobile"
          className="mx-auto flex max-w-7xl flex-col gap-1 px-4 py-6"
        >
          {links.map((link, i) => (
            <NavigationItem
              key={link.label}
              href={link.href}
              size="mobile"
              active={
                link.href === "/"
                  ? pathname === "/"
                  : pathname.startsWith(link.href)
              }
              onClick={onClose}
              style={{
                animation: "var(--animate-rise)",
                animationDelay: `${i * 45}ms`,
              }}
            >
              {link.label}
            </NavigationItem>
          ))}
          <NavigationItem
            href={routes.login}
            size="mobile"
            onClick={onClose}
            className="!text-muted"
          >
            Login
          </NavigationItem>
          <Button
            href={routes.cart}
            size="lg"
            variant="secondary"
            className="mt-4 w-full"
            onClick={onClose}
          >
            <Icon name="ticket" className="h-4 w-4" />
            View basket
          </Button>
          <Button
            href={routes.competitions}
            size="lg"
            className="mt-2 w-full"
            onClick={onClose}
          >
            ENTER NOW
          </Button>
        </nav>
      </div>
    </div>
  );
}
