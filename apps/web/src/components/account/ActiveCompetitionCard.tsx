import Badge from "@/components/ui/Badge";
import Button from "@/components/ui/Button";
import Icon from "@/components/ui/Icon";
import ImageFrame from "@/components/media/ImageFrame";
import { drawDateLong } from "@/lib/format";
import { competitionStatusLabel, type AccountCompetition } from "@/lib/account/summary";
import { routes } from "@/lib/routes";

/** A competition the customer holds tickets in. Every value shown comes
 * from the entries or competition API; unknown draw times are said so. */
export default function ActiveCompetitionCard({ competition }: { competition: AccountCompetition }) {
  const { slug, title, status, ticketCount, drawAt, image } = competition;
  const open = status === "active" || status === "scheduled";

  return (
    <article className="flex flex-col overflow-hidden rounded-card border border-line bg-card card-shadow">
      {image ? (
        <ImageFrame src={image} alt={title} ratio="16/9" className="rounded-none" />
      ) : (
        <div className="grid aspect-video place-items-center bg-mist text-gold-700" aria-hidden="true">
          <Icon name="trophy" className="h-8 w-8" />
        </div>
      )}

      <div className="flex flex-1 flex-col gap-4 p-5">
        <div className="flex items-start justify-between gap-3">
          <h3 className="font-display text-base font-extrabold leading-snug tracking-tight text-heading">
            {title}
          </h3>
          <Badge tone={open ? "new" : "neutral"} size="sm" className="shrink-0">
            {competitionStatusLabel(status)}
          </Badge>
        </div>

        <dl className="grid grid-cols-2 gap-3 text-sm">
          <div>
            <dt className="t-caption text-muted">Your tickets</dt>
            <dd className="mt-0.5 font-semibold tabular-nums text-heading">{ticketCount}</dd>
          </div>
          <div>
            <dt className="t-caption text-muted">Draw</dt>
            <dd className="mt-0.5 font-medium text-heading">
              {drawAt ? drawDateLong(drawAt) : "To be confirmed"}
            </dd>
          </div>
        </dl>

        <Button href={routes.competition(slug)} variant="secondary" size="sm" block className="mt-auto">
          View competition
        </Button>
      </div>
    </article>
  );
}
