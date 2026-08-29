import Image from "next/image";
import type { Winner } from "@/data/homepageMockData";
import Icon from "./Icon";

export default function WinnerCard({ winner }: { winner: Winner }) {
  return (
    <figure className="group relative flex flex-col overflow-hidden rounded-card border border-line bg-card card-shadow">
      <div className="relative">
        <Image
          src={winner.photo}
          alt={`${winner.name} from ${winner.location}`}
          width={480}
          height={480}
          sizes="(max-width: 640px) 100vw, (max-width: 1024px) 50vw, 33vw"
          className="aspect-[5/4] w-full object-cover transition-transform duration-500 group-hover:scale-105"
        />
        <span className="absolute left-3 top-3 inline-flex items-center gap-1.5 rounded-full brand-gradient px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider text-white">
          <Icon name="trophy" className="h-3 w-3" />
          Winner
        </span>
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
