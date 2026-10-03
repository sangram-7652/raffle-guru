import Button from "../ui/Button";
import Section from "../layout/Section";
import SectionHeading from "../layout/SectionHeading";
import CompetitionSectionBody from "./CompetitionSectionBody";
import { loadHomepageCompetitions, endingSoonSectionParams } from "@/lib/competitions/homepage";
import { routes } from "@/lib/routes";

/** Server component: live competitions closing soonest, from the public API. */
export default async function EndingSoon() {
  const result = await loadHomepageCompetitions(endingSoonSectionParams);

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
        <CompetitionSectionBody result={result} columns={3} />
      </div>
    </Section>
  );
}
