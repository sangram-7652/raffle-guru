import Link from "next/link";
import type { NavLink } from "@/data/site";

type FooterColumnProps = {
  heading: string;
  links: NavLink[];
};

export default function FooterColumn({ heading, links }: FooterColumnProps) {
  return (
    <div>
      <p className="font-display text-xs font-bold uppercase tracking-[0.2em] text-white">
        {heading}
      </p>
      <ul className="mt-4 space-y-2.5">
        {links.map((link) => (
          <li key={link.label}>
            <Link
              href={link.href}
              className="text-sm text-white/65 transition-colors hover:text-gold focus-visible:outline-none focus-visible:text-gold"
            >
              {link.label}
            </Link>
          </li>
        ))}
      </ul>
    </div>
  );
}
