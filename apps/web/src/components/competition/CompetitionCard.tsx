import Link from "next/link";
import type { Competition } from "@/data/competitions";
import { gbp } from "@/lib/format";
import { routes } from "@/lib/routes";
import Badge from "../ui/Badge";
import Button from "../ui/Button";
import Icon from "../ui/Icon";
import ProgressBar from "../ui/ProgressBar";
import ImageFrame from "../media/ImageFrame";

type CompetitionCardProps = {
  competition: Competition;
  priority?: boolean;
};

/**
 * Premium reusable competition card — the approved homepage treatment.
 * Hierarchy: image → category badge → title → entry price → progress →
 * draw info → CTA. Built on design-system primitives (ImageFrame / Badge /
 * ProgressBar / Button); no bespoke styling.
 */
export default function CompetitionCard({
  competition,
  priority = false,
}: CompetitionCardProps) {
  const {
    title,
    blurb,
    image,
    category,
    entryPrice,
    cashAlternative,
    soldPct,
    drawLabel,
    slug,
  } = competition;

  const href = slug ? routes.competition(slug) : routes.competitions;
  const soldOut = soldPct >= 100;

  return (
    <article className="group flex h-full flex-col overflow-hidden rounded-card border border-line bg-card card-shadow transition-all duration-300 hover:-translate-y-1 hover:border-gold/40 hover:shadow-[var(--shadow-glow)]">
      <div className="relative">
        <Link href={href} tabIndex={-1} aria-hidden className="block">
          <ImageFrame
            src={image}
            alt={title}
            ratio="4/3"
            priority={priority}
            sizes="(max-width: 640px) 100vw, (max-width: 1024px) 50vw, 25vw"
            imgClassName="transition-transform duration-500 ease-out group-hover:scale-[1.06]"
          />
        </Link>
        <Badge
          tone={soldOut ? "sold-out" : "neutral"}
          className="absolute left-3 top-3"
          icon={
            soldOut ? undefined : (
              <Icon name="spark" className="h-3 w-3 text-gold-700" />
            )
          }
        >
          {soldOut ? "Sold out" : category}
        </Badge>
      </div>

      <div className="flex flex-1 flex-col p-5">
        <h3 className="t-h4 text-heading">
          <Link
            href={href}
            className="rounded outline-none transition-colors hover:text-gold-700 focus-visible:ring-2 focus-visible:ring-gold-700/50"
          >
            {title}
          </Link>
        </h3>
        <p className="mt-1.5 line-clamp-2 text-sm text-muted">{blurb}</p>

        <div className="mt-3 flex items-baseline gap-2">
          <span className="font-display text-xl font-extrabold text-heading">
            {gbp(entryPrice)}
          </span>
          <span className="text-xs text-muted">per entry</span>
          {cashAlternative && (
            <span className="ml-auto text-xs font-medium text-gold-700">
              {cashAlternative}
            </span>
          )}
        </div>

        <ProgressBar value={soldPct} className="mt-4" />

        <div className="mt-4 flex items-center gap-1.5 text-xs text-muted">
          <Icon name="clock" className="h-3.5 w-3.5" />
          Draw <span className="font-semibold text-heading">{drawLabel}</span>
        </div>

        <Button
          href={href}
          block
          variant={soldOut ? "secondary" : "primary"}
          className="mt-4"
        >
          {soldOut ? "VIEW DRAW" : "ENTER NOW"}
          <Icon name="arrow" className="h-4 w-4" />
        </Button>
      </div>
    </article>
  );
}
