"use client";

import { usePathname } from "next/navigation";
import NavigationItem from "./NavigationItem";
import type { NavLink } from "@/data/site";

const isActive = (pathname: string, href: string) =>
  href === "/" ? pathname === "/" : pathname.startsWith(href);

/** Inline primary navigation — shown from `lg` up. */
export default function DesktopNavigation({ links }: { links: NavLink[] }) {
  const pathname = usePathname();
  return (
    <nav aria-label="Primary" className="hidden items-center gap-8 lg:flex">
      {links.map((link) => (
        <NavigationItem
          key={link.label}
          href={link.href}
          active={isActive(pathname, link.href)}
        >
          {link.label}
        </NavigationItem>
      ))}
    </nav>
  );
}
