import Button from "../ui/Button";
import Icon from "../ui/Icon";
import Reveal from "../ui/Reveal";
import Section from "../layout/Section";
import SectionHeading from "../layout/SectionHeading";
import WinnerCard from "./WinnerCard";
import { winners } from "@/data/homepageMockData";

export default function WinnerSection() {
  return (
    <Section
      id="winners"
      surface="mist"
      bordered
      className="scroll-mt-24 overflow-hidden"
    >
      <div className="flex flex-col gap-6 sm:flex-row sm:items-end sm:justify-between">
        <SectionHeading
          eyebrow="Winners"
          title="Real people. Real wins."
          subtitle="Every week we hand over the keys, transfer the cash and post the photos. Here are some of the most recent."
        />
        <Button href="#winners" variant="secondary" className="shrink-0">
          <Icon name="trophy" className="h-4 w-4" />
          VIEW ALL WINNERS
        </Button>
      </div>

      <div className="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
        {winners.map((winner, i) => (
          <Reveal key={winner.id} delay={i * 70} className="h-full">
            <WinnerCard winner={winner} />
          </Reveal>
        ))}
      </div>
    </Section>
  );
}
