import Logo from "./Logo";
import Icon from "./Icon";
import { footerLinks } from "@/data/homepageMockData";

const socials = ["instagram", "facebook", "tiktok", "youtube"] as const;

export default function Footer() {
  return (
    <footer className="border-t border-line bg-mist">
      <div className="mx-auto max-w-7xl px-4 py-16 sm:px-6 xl:px-8">
        <div className="grid gap-12 lg:grid-cols-[1.4fr_2fr]">
          <div>
            <Logo className="h-16 w-auto" />
            <p className="mt-4 max-w-xs text-sm leading-relaxed text-muted">
              A premium UK competition platform. Incredible prizes, transparent
              draws and real winners — every single week.
            </p>
            <div className="mt-6 flex gap-3">
              {socials.map((s) => (
                <a
                  key={s}
                  href="#"
                  aria-label={s}
                  className="grid h-10 w-10 place-items-center rounded-full border border-line bg-panel text-muted transition-colors hover:border-pink/50 hover:text-heading"
                >
                  <Icon name={s} className="h-4 w-4" />
                </a>
              ))}
            </div>
          </div>

          <div className="grid grid-cols-2 gap-8 sm:grid-cols-3">
            {Object.entries(footerLinks).map(([heading, links]) => (
              <div key={heading}>
                <p className="font-display text-xs font-bold uppercase tracking-[0.2em] text-heading">
                  {heading}
                </p>
                <ul className="mt-4 space-y-2.5">
                  {links.map((link) => (
                    <li key={link.label}>
                      <a
                        href={link.href}
                        className="text-sm text-muted transition-colors hover:text-heading"
                      >
                        {link.label}
                      </a>
                    </li>
                  ))}
                </ul>
              </div>
            ))}
          </div>
        </div>

        <div className="mt-14 flex flex-col gap-4 border-t border-line pt-8 text-xs text-muted sm:flex-row sm:items-center sm:justify-between">
          <p>© {new Date().getFullYear()} RaffleGuru. All rights reserved.</p>
          <p className="flex items-center gap-2">
            <Icon name="shield" className="h-3.5 w-3.5 text-pink-600" />
            18+ only. Please play responsibly. Full terms apply.
          </p>
        </div>

        <p className="mt-6 rounded-xl border border-line bg-card p-3 text-center text-[11px] leading-relaxed text-muted">
          Design concept only — this is a non-functional homepage prototype
          created for client review. No purchases, accounts or draws are live.
        </p>
      </div>
    </footer>
  );
}
