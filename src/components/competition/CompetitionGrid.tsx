import type { Competition } from "@/data/homepageMockData";
import CompetitionCard from "./CompetitionCard";
import Reveal from "../ui/Reveal";

type CompetitionGridProps = {
  competitions: Competition[];
  /** cols at lg breakpoint */
  columns?: 3 | 4;
};

export default function CompetitionGrid({
  competitions,
  columns = 3,
}: CompetitionGridProps) {
  return (
    <div
      className={`grid gap-6 sm:grid-cols-2 ${
        columns === 4 ? "lg:grid-cols-4" : "lg:grid-cols-3"
      }`}
    >
      {competitions.map((competition, i) => (
        <Reveal key={competition.id} delay={i * 80} className="h-full">
          <CompetitionCard competition={competition} priority={i === 0} />
        </Reveal>
      ))}
    </div>
  );
}
