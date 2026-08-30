import type { NavLink } from "@/data/homepageMockData";

type FooterColumnProps = {
  heading: string;
  links: NavLink[];
};

export default function FooterColumn({ heading, links }: FooterColumnProps) {
  return (
    <div>
      <p className="font-display text-xs font-bold uppercase tracking-[0.2em] text-heading">
        {heading}
      </p>
      <ul className="mt-4 space-y-2.5">
        {links.map((link) => (
          <li key={link.label}>
            <a
              href={link.href}
              className="text-sm text-muted transition-colors hover:text-heading focus-visible:outline-none focus-visible:text-heading"
            >
              {link.label}
            </a>
          </li>
        ))}
      </ul>
    </div>
  );
}
