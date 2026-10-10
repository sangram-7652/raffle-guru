import Button from "../ui/Button";
import Icon from "../ui/Icon";
import Reveal from "../ui/Reveal";
import Section from "../layout/Section";
import SectionHeading from "../layout/SectionHeading";
import WinnerCard from "./WinnerCard";
import { winners } from "@/data/homepageMockData";
import { routes } from "@/lib/routes";

export default function WinnerSection() {
  return (
    <Section
      id="winners"
      bordered
      className="scroll-mt-24 overflow-hidden"
    >
      <div className="flex flex-col gap-6 sm:flex-row sm:items-end sm:justify-between">
        <SectionHeading
          eyebrow="Winners"
          title="Real people. Real wins."
          subtitle="Every week we hand over the keys, transfer the cash and post the photos. Here are some of the most recent."
        />
        <Button href={routes.winners} variant="secondary" className="shrink-0">
          <Icon name="trophy" className="h-4 w-4" />
          VIEW ALL WINNERS
        </Button>
      </div>

      <div className="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
        {/* Exactly 3 on the homepage, enforced here (not just by how
            many entries happen to be in the data file) — the full list
            lives on the dedicated /winners page via a different data
            source (@/data/winners), not this one. */}
        {winners.slice(0, 3).map((winner, i) => (
          <Reveal key={winner.id} delay={i * 70} className="h-full">
            <WinnerCard winner={winner} />
          </Reveal>
        ))}
      </div>
    </Section>
  );
}
