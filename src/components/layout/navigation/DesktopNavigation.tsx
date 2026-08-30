import NavigationItem from "./NavigationItem";
import type { NavLink } from "@/data/homepageMockData";

/** Inline primary navigation — shown from `lg` up. */
export default function DesktopNavigation({ links }: { links: NavLink[] }) {
  return (
    <nav aria-label="Primary" className="hidden items-center gap-8 lg:flex">
      {links.map((link) => (
        <NavigationItem key={link.label} href={link.href}>
          {link.label}
        </NavigationItem>
      ))}
    </nav>
  );
}
