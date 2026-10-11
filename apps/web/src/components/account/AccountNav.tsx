"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import Icon from "@/components/ui/Icon";
import { routes } from "@/lib/routes";

type IconName = React.ComponentProps<typeof Icon>["name"];

type NavItem =
  | { kind: "link"; label: string; href: string; icon: IconName; match: "exact" | "prefix" }
  | { kind: "soon"; label: string; icon: IconName };

/** Account sections in the order customers look for them. `soon` items
 * are real sections the backend does not serve yet — shown, not linked. */
const ITEMS: NavItem[] = [
  { kind: "link", label: "Overview", href: routes.account, icon: "grid", match: "exact" },
  { kind: "link", label: "My entries", href: routes.accountEntries, icon: "ticket", match: "prefix" },
  { kind: "link", label: "My wins", href: routes.accountWinner, icon: "trophy", match: "prefix" },
  { kind: "link", label: "Account details", href: routes.accountDetails, icon: "user", match: "prefix" },
  { kind: "soon", label: "Addresses", icon: "pin" },
  { kind: "soon", label: "Communication", icon: "bell" },
  { kind: "link", label: "Responsible play", href: routes.responsiblePlay, icon: "shield", match: "prefix" },
];

function isActive(pathname: string, item: { href: string; match: "exact" | "prefix" }) {
  return item.match === "exact"
    ? pathname === item.href
    : pathname === item.href || pathname.startsWith(`${item.href}/`);
}

type AccountNavProps = {
  onSignOut: () => void;
  signingOut: boolean;
};

/**
 * Account section navigation. Desktop: a vertical panel beside the content.
 * Below `lg`: a compact 4-column tile grid above the content — every tile is
 * a full touch target, and nothing scrolls sideways.
 */
export default function AccountNav({ onSignOut, signingOut }: AccountNavProps) {
  const pathname = usePathname();

  return (
    <nav aria-label="Account" className="lg:sticky lg:top-28">
      {/* Mobile / tablet: tile grid */}
      <ul className="grid grid-cols-4 gap-2 sm:grid-cols-8 lg:hidden">
        {ITEMS.map((item) => (
          <li key={item.label}>
            <NavTile item={item} pathname={pathname} />
          </li>
        ))}
        <li>
          <SignOutTile onSignOut={onSignOut} signingOut={signingOut} />
        </li>
      </ul>

      {/* Desktop: vertical panel */}
      <div className="hidden rounded-card border border-line bg-card p-3 card-shadow lg:block">
        <p className="t-eyebrow px-3 pb-3 pt-2 text-muted">Account</p>
        <ul className="space-y-1">
          {ITEMS.map((item) => (
            <li key={item.label}>
              <NavRow item={item} pathname={pathname} />
            </li>
          ))}
        </ul>
        <div className="mt-3 border-t border-line pt-3">
          <button
            type="button"
            onClick={onSignOut}
            disabled={signingOut}
            className="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-sm font-medium text-muted transition-colors hover:bg-mist hover:text-heading focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold-700/60 disabled:opacity-50"
          >
            <Icon name="logout" className="h-4 w-4 shrink-0" />
            {signingOut ? "Signing out…" : "Sign out"}
          </button>
        </div>
      </div>
    </nav>
  );
}

function NavRow({ item, pathname }: { item: NavItem; pathname: string }) {
  const base =
    "flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold-700/60";

  if (item.kind === "soon") {
    return (
      <span
        aria-disabled="true"
        className={`${base} cursor-default text-muted/70`}
      >
        <Icon name={item.icon} className="h-4 w-4 shrink-0" />
        <span className="flex-1">{item.label}</span>
        <span className="t-caption text-muted/80">Soon</span>
      </span>
    );
  }

  const active = isActive(pathname, item);
  return (
    <Link
      href={item.href}
      aria-current={active ? "page" : undefined}
      className={`${base} ${
        active
          ? "bg-mist text-heading shadow-[inset_2px_0_0_var(--color-gold)]"
          : "text-muted hover:bg-mist hover:text-heading"
      }`}
    >
      <Icon name={item.icon} className={`h-4 w-4 shrink-0 ${active ? "text-gold-700" : ""}`} />
      {item.label}
    </Link>
  );
}

function NavTile({ item, pathname }: { item: NavItem; pathname: string }) {
  const tile =
    "flex h-full min-h-[4.25rem] flex-col items-center justify-center gap-1.5 rounded-xl border px-1.5 py-2.5 text-center t-caption font-semibold leading-tight focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold-700/60";

  if (item.kind === "soon") {
    return (
      <span aria-disabled="true" className={`${tile} border-line bg-panel text-muted/70`}>
        <Icon name={item.icon} className="h-5 w-5" />
        {item.label}
      </span>
    );
  }

  const active = isActive(pathname, item);
  return (
    <Link
      href={item.href}
      aria-current={active ? "page" : undefined}
      className={`${tile} ${
        active ? "border-gold/60 bg-mist text-heading" : "border-line bg-card text-muted"
      }`}
    >
      <Icon name={item.icon} className={`h-5 w-5 ${active ? "text-gold-700" : ""}`} />
      {item.label}
    </Link>
  );
}

function SignOutTile({ onSignOut, signingOut }: { onSignOut: () => void; signingOut: boolean }) {
  return (
    <button
      type="button"
      onClick={onSignOut}
      disabled={signingOut}
      className="flex h-full min-h-[4.25rem] w-full flex-col items-center justify-center gap-1.5 rounded-xl border border-line bg-card px-1.5 py-2.5 text-center t-caption font-semibold leading-tight text-muted transition-colors hover:text-heading focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold-700/60 disabled:opacity-50"
    >
      <Icon name="logout" className="h-5 w-5" />
      {signingOut ? "Signing out…" : "Sign out"}
    </button>
  );
}
