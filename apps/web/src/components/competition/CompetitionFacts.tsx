import Icon from "../ui/Icon";
import { gbp, drawDateLong } from "@/lib/format";
import type { Competition } from "@/data/competitions";

export default function CompetitionFacts({
  competition,
}: {
  competition: Competition;
}) {
  const facts: { icon: "gift" | "ticket" | "clock" | "spark"; label: string; value: string }[] =
    [
      {
        icon: "gift",
        label: "Prize value",
        value: gbp(competition.prizeValue),
      },
      {
        icon: "ticket",
        label: "Total tickets",
        value: competition.totalEntries.toLocaleString("en-GB"),
      },
      {
        icon: "spark",
        label: "Entry price",
        value: `${gbp(competition.entryPrice)} each`,
      },
      {
        icon: "clock",
        label: "Live draw",
        value: drawDateLong(competition.drawDate),
      },
    ];

  return (
    <>
      <dl className="grid grid-cols-2 gap-px overflow-hidden rounded-2xl border border-line bg-line lg:grid-cols-4">
        {facts.map((f) => (
          <div key={f.label} className="bg-card p-4">
            <dt className="flex items-center gap-1.5 text-xs text-muted">
              <Icon name={f.icon} className="h-3.5 w-3.5 text-gold-700" />
              {f.label}
            </dt>
            <dd className="mt-1 font-display text-sm font-extrabold text-heading">
              {f.value}
            </dd>
          </div>
        ))}
      </dl>
      {competition.maxEntriesPerUser !== null && (
        <p className="mt-3 text-xs text-muted">
          Up to {competition.maxEntriesPerUser.toLocaleString("en-GB")} entries per customer.
        </p>
      )}
    </>
  );
}
