import Button from "../ui/Button";
import Icon from "../ui/Icon";
import Section from "../layout/Section";
import SectionHeading from "../layout/SectionHeading";
import CompetitionSectionBody from "./CompetitionSectionBody";
import { loadHomepageCompetitions, featuredSectionParams } from "@/lib/competitions/homepage";
import { routes } from "@/lib/routes";

/** Server component: live featured competitions from the public API. */
export default async function FeaturedCompetitions() {
  const result = await loadHomepageCompetitions(featuredSectionParams);

  return (
    <Section id="featured" className="scroll-mt-24">
      <SectionHeading
        eyebrow="The Big Prizes"
        title="Headline competitions, live now"
        subtitle="The prizes everyone's talking about. Limited entries, fixed draw dates, no rollover."
        align="center"
        className="mx-auto"
      />

      <div className="mt-12">
        <CompetitionSectionBody result={result} columns={4} />
      </div>

      <div className="mt-12 flex justify-center">
        <Button href={routes.competitions} size="lg">
          BROWSE ALL COMPETITIONS
          <Icon name="arrow" className="h-4 w-4" />
        </Button>
      </div>
    </Section>
  );
}
