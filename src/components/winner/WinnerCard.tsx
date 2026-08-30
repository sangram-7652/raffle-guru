import type { Winner } from "@/data/homepageMockData";
import Badge from "../ui/Badge";
import Icon from "../ui/Icon";
import ImageFrame from "../media/ImageFrame";

export default function WinnerCard({ winner }: { winner: Winner }) {
  return (
    <figure className="group relative flex flex-col overflow-hidden rounded-card border border-line bg-card card-shadow">
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
      </div>

      <figcaption className="flex flex-1 flex-col p-5">
        <p className="font-display text-lg font-extrabold tracking-tight text-heading">
          {winner.name}
          <span className="ml-2 text-xs font-medium text-muted">
            {winner.location}
          </span>
        </p>
        <p className="mt-1 text-sm font-semibold text-pink-600">{winner.prize}</p>
        <p className="mt-0.5 text-xs text-muted">{winner.competition}</p>
        <p className="mt-4 inline-flex w-fit items-center gap-1.5 rounded-full border border-line bg-panel px-2.5 py-1 font-mono text-xs text-heading">
          <Icon name="ticket" className="h-3.5 w-3.5 text-pink-600" />
          Ticket {winner.ticket}
        </p>
      </figcaption>
    </figure>
  );
}
