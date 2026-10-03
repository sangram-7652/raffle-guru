import Link from "next/link";
import { drawDateLong } from "@/lib/format";
import type { AccountCompetition } from "@/lib/account/summary";
import { routes } from "@/lib/routes";

/** Competitions with a draw still ahead, soonest first. Shows the draw
 * time the API provides — there is no countdown, by design. */
export default function UpcomingDrawsList({ draws }: { draws: AccountCompetition[] }) {
  if (draws.length === 0) {
    return <p className="text-sm text-muted">No upcoming draws for your entries.</p>;
  }

  return (
    <ul className="divide-y divide-line">
      {draws.map((draw) => (
        <li key={draw.id} className="flex items-start justify-between gap-3 py-3 first:pt-0 last:pb-0">
          <div className="min-w-0">
            <p className="truncate text-sm font-semibold text-heading">{draw.title}</p>
            <p className="mt-0.5 text-xs text-muted">
              {draw.drawAt ? drawDateLong(draw.drawAt) : "Draw time to be confirmed"}
              {" · "}
              {draw.ticketCount} {draw.ticketCount === 1 ? "ticket" : "tickets"}
            </p>
          </div>
          <Link
            href={routes.competition(draw.slug)}
            className="shrink-0 rounded text-sm font-semibold text-gold-700 transition-colors hover:text-heading focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold-700/60"
          >
            View
            <span className="sr-only"> {draw.title}</span>
          </Link>
        </li>
      ))}
    </ul>
  );
}
