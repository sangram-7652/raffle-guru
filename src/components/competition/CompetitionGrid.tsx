import type { Competition } from "@/data/homepageMockData";
import CompetitionCard from "./CompetitionCard";
import Reveal from "../ui/Reveal";

type CompetitionGridProps = {
  competitions: Competition[];
  /** cols at lg breakpoint */
  columns?: 2 | 3 | 4;
  /** stagger cards in on scroll (homepage). Off for filterable listings. */
  reveal?: boolean;
};

const colClass: Record<NonNullable<CompetitionGridProps["columns"]>, string> = {
  2: "lg:grid-cols-2",
  3: "lg:grid-cols-3",
  4: "lg:grid-cols-4",
};

export default function CompetitionGrid({
  competitions,
  columns = 3,
  reveal = true,
}: CompetitionGridProps) {
  return (
    <div className={`grid gap-6 sm:grid-cols-2 ${colClass[columns]}`}>
      {competitions.map((competition, i) =>
        reveal ? (
          <Reveal key={competition.id} delay={i * 80} className="h-full">
            <CompetitionCard competition={competition} priority={i === 0} />
          </Reveal>
        ) : (
          <CompetitionCard
            key={competition.id}
            competition={competition}
            priority={i < 2}
          />
        ),
      )}
    </div>
  );
}
