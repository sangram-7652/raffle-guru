import Button from "../ui/Button";
import Icon from "../ui/Icon";
import Section from "../layout/Section";
import SectionHeading from "../layout/SectionHeading";
import CompetitionGrid from "../competition/CompetitionGrid";
import { featuredCompetitions } from "@/data/homepageMockData";
import { routes } from "@/lib/routes";

export default function FeaturedCompetitions() {
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
        <CompetitionGrid competitions={featuredCompetitions} columns={4} />
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
