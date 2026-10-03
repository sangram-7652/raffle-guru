import CompetitionGrid from "../competition/CompetitionGrid";
import EmptyState from "../ui/EmptyState";
import type { HomepageCompetitionsResult } from "@/lib/competitions/homepage";

/** Renders one homepage competition section from its loaded state. */
export default function CompetitionSectionBody({
  result,
  columns,
}: {
  result: HomepageCompetitionsResult;
  columns: 3 | 4;
}) {
  if (result.state === "ready") {
    return <CompetitionGrid competitions={result.competitions} columns={columns} />;
  }

  if (result.state === "empty") {
    return (
      <EmptyState
        icon="ticket"
        title="No live competitions right now"
        description="New competitions are added every week — check back soon."
      />
    );
  }

  return (
    <EmptyState
      icon="spark"
      title="Competitions are unavailable right now"
      description="We couldn't load the latest competitions. Please refresh the page in a moment."
    />
  );
}

/** Placeholder shown while a section's data is still loading. */
export function CompetitionSectionSkeleton({ columns }: { columns: 3 | 4 }) {
  return (
    <div
      className={`grid gap-6 sm:grid-cols-2 ${columns === 4 ? "lg:grid-cols-4" : "lg:grid-cols-3"}`}
      aria-hidden="true"
    >
      {Array.from({ length: columns }, (_, i) => (
        <div key={i} className="aspect-[3/4] animate-pulse rounded-card border border-line bg-mist" />
      ))}
    </div>
  );
}
