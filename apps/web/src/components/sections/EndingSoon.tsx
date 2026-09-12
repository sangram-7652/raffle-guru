import Button from "../ui/Button";
import Section from "../layout/Section";
import SectionHeading from "../layout/SectionHeading";
import CompetitionGrid from "../competition/CompetitionGrid";
import { endingSoon } from "@/data/homepageMockData";
import { routes } from "@/lib/routes";

export default function EndingSoon() {
  return (
    <Section id="ending-soon" className="scroll-mt-24">
      <div className="flex flex-col gap-6 sm:flex-row sm:items-end sm:justify-between">
        <SectionHeading
          eyebrow="Ending Soon"
          title="Closer than you think"
          subtitle="Your next chance could be closer than you think — these draws are almost here."
        />
        <Button href={routes.competitions} variant="secondary" className="shrink-0">
          View all competitions
        </Button>
      </div>

      <div className="mt-12">
        <CompetitionGrid competitions={endingSoon} columns={3} />
      </div>
    </Section>
  );
}
