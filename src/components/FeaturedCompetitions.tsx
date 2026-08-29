import Button from "./Button";
import CompetitionGrid from "./CompetitionGrid";
import Icon from "./Icon";
import SectionHeading from "./SectionHeading";
import { featuredCompetitions } from "@/data/homepageMockData";

export default function FeaturedCompetitions() {
  return (
    <section
      id="featured"
      className="mx-auto max-w-7xl scroll-mt-24 px-4 py-20 sm:px-6 lg:py-28 xl:px-8"
    >
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
        <Button href="#categories" size="lg">
          BROWSE ALL COMPETITIONS
          <Icon name="arrow" className="h-4 w-4" />
        </Button>
      </div>
    </section>
  );
}
