import type { ReactNode } from "react";

type AccountPageHeadingProps = {
  eyebrow: string;
  title: ReactNode;
  lede?: ReactNode;
};

/**
 * Masthead for an account section. Deliberately a `.t-h2`, not a `.t-h1`:
 * the account area is a working dashboard, so the heading informs rather
 * than dominating the viewport.
 */
export default function AccountPageHeading({ eyebrow, title, lede }: AccountPageHeadingProps) {
  return (
    <header className="border-b border-line pb-6">
      <span className="t-eyebrow inline-flex items-center gap-2 text-gold-700">
        <span className="h-px w-6 bg-gold/60" />
        {eyebrow}
      </span>
      <h1 className="t-h2 mt-3 text-heading">{title}</h1>
      {lede && <p className="t-body mt-2 max-w-xl text-muted">{lede}</p>}
    </header>
  );
}
