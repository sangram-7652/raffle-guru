import Button from "./Button";
import CompetitionGrid from "./CompetitionGrid";
import SectionHeading from "./SectionHeading";
import { endingSoon } from "@/data/homepageMockData";

export default function EndingSoon() {
  return (
    <section
      id="ending-soon"
      className="mx-auto max-w-7xl scroll-mt-24 px-4 py-20 sm:px-6 lg:py-28 xl:px-8"
    >
      <div className="flex flex-col gap-6 sm:flex-row sm:items-end sm:justify-between">
        <SectionHeading
          eyebrow="Ending Soon"
          title="Closer than you think"
          subtitle="Your next chance could be closer than you think — these draws are almost here."
        />
        <Button
          href="#featured"
          variant="secondary"
          size="md"
          className="shrink-0"
        >
          View all competitions
        </Button>
      </div>

      <div className="mt-12">
        <CompetitionGrid competitions={endingSoon} columns={3} />
      </div>
    </section>
  );
}
