import Image from "next/image";
import type { Competition } from "@/data/homepageMockData";
import { gbp } from "@/lib/format";
import Icon from "./Icon";
import ProgressBar from "./ProgressBar";

type CompetitionCardProps = {
  competition: Competition;
  priority?: boolean;
};

/**
 * Premium reusable competition card.
 * Visual hierarchy: image → type badge → title → entry price →
 * progress → draw info → CTA.
 */
export default function CompetitionCard({
  competition,
  priority = false,
}: CompetitionCardProps) {
  const { title, blurb, image, category, entryPrice, cashAlternative, soldPct, drawLabel } =
    competition;

  return (
    <article className="group flex h-full flex-col overflow-hidden rounded-card border border-line bg-card card-shadow transition-all duration-300 hover:-translate-y-1 hover:border-pink/40 hover:shadow-[0_30px_60px_-22px_rgba(255,45,135,0.28)]">
      <div className="relative overflow-hidden">
        <Image
          src={image}
          alt={title}
          width={800}
          height={600}
          priority={priority}
          sizes="(max-width: 640px) 100vw, (max-width: 1024px) 50vw, 25vw"
          className="aspect-[4/3] w-full object-cover transition-transform duration-500 ease-out group-hover:scale-[1.06]"
        />
        <span className="absolute left-3 top-3 inline-flex items-center gap-1.5 rounded-full border border-line bg-white/90 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider text-heading shadow-sm backdrop-blur-md">
          <Icon name="spark" className="h-3 w-3 text-pink-600" />
          {category}
        </span>
      </div>

      <div className="flex flex-1 flex-col p-5">
        <h3 className="font-display text-lg font-extrabold leading-snug tracking-tight text-heading">
          {title}
        </h3>
        <p className="mt-1.5 line-clamp-2 text-sm text-muted">{blurb}</p>

        <div className="mt-3 flex items-baseline gap-2">
          <span className="font-display text-xl font-extrabold text-heading">
            {gbp(entryPrice)}
          </span>
          <span className="text-xs text-muted">per entry</span>
          {cashAlternative && (
            <span className="ml-auto text-xs font-medium text-pink-600">
              {cashAlternative}
            </span>
          )}
        </div>

        <ProgressBar value={soldPct} className="mt-4" />

        <div className="mt-4 flex items-center gap-1.5 text-xs text-muted">
          <Icon name="clock" className="h-3.5 w-3.5" />
          Draw <span className="font-semibold text-heading">{drawLabel}</span>
        </div>

        <a
          href="#featured"
          className="mt-4 inline-flex h-11 w-full items-center justify-center gap-2 rounded-full brand-gradient font-display text-sm font-bold tracking-tight text-white transition-all duration-200 hover:-translate-y-0.5 hover:shadow-[0_14px_34px_-10px_var(--color-pink)] active:scale-[0.98]"
        >
          ENTER NOW
          <Icon name="arrow" className="h-4 w-4" />
        </a>
      </div>
    </article>
  );
}
