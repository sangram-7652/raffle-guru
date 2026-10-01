import Badge from "../ui/Badge";
import Icon from "../ui/Icon";
import ImageFrame from "../media/ImageFrame";
import { dateMedium } from "@/lib/format";

export type WinnerCardData = {
  name: string;
  location: string;
  prize: string;
  competition: string;
  ticket: string;
  photo: string;
  /** ISO draw date — shown when provided */
  drawDate?: string;
  quote?: string;
};

export default function WinnerCard({ winner }: { winner: WinnerCardData }) {
  return (
    <figure className="group relative flex h-full flex-col overflow-hidden rounded-card border border-line bg-card card-shadow">
      <div className="relative">
        <ImageFrame
          src={winner.photo}
          alt={`${winner.name} from ${winner.location}`}
          ratio="5/4"
          sizes="(max-width: 640px) 100vw, (max-width: 1024px) 50vw, 33vw"
          imgClassName="transition-transform duration-500 group-hover:scale-105"
        />
        <Badge tone="winner" icon className="absolute left-3 top-3">
          Winner
        </Badge>
        {winner.drawDate && (
          <span className="absolute right-3 top-3 rounded-full bg-ink/70 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wider text-white backdrop-blur">
            {dateMedium(winner.drawDate)}
          </span>
        )}
      </div>

      <figcaption className="flex flex-1 flex-col p-5">
        <p className="font-display text-lg font-extrabold tracking-tight text-heading">
          {winner.name}
          <span className="ml-2 text-xs font-medium text-muted">
            {winner.location}
          </span>
        </p>
        <p className="mt-1 text-sm font-semibold text-gold">{winner.prize}</p>
        <p className="mt-0.5 text-xs text-muted">{winner.competition}</p>
        {winner.quote && (
          <p className="mt-3 text-sm italic leading-relaxed text-muted">
            &ldquo;{winner.quote}&rdquo;
          </p>
        )}
        <p className="mt-4 inline-flex w-fit items-center gap-1.5 rounded-full border border-line bg-panel px-2.5 py-1 font-mono text-xs text-heading">
          <Icon name="ticket" className="h-3.5 w-3.5 text-gold" />
          Ticket {winner.ticket}
        </p>
      </figcaption>
    </figure>
  );
}
