import { SectionHeading } from "@/components/layout";
import CompetitionGrid from "./CompetitionGrid";
import type { Competition } from "@/data/competitions";

export default function RelatedCompetitions({
  competitions,
}: {
  competitions: Competition[];
}) {
  if (competitions.length === 0) return null;
  return (
    <section aria-label="Related competitions">
      <SectionHeading as="h2" eyebrow="You might also like" title="Related competitions" />
      <div className="mt-8">
        <CompetitionGrid competitions={competitions} columns={3} reveal={false} />
      </div>
    </section>
  );
}
