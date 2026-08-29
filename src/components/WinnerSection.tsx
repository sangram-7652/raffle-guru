import Button from "./Button";
import Icon from "./Icon";
import Reveal from "./Reveal";
import SectionHeading from "./SectionHeading";
import WinnerCard from "./WinnerCard";
import { winners } from "@/data/homepageMockData";

export default function WinnerSection() {
  return (
    <section
      id="winners"
      className="relative scroll-mt-24 overflow-hidden border-y border-line bg-mist py-20 lg:py-28"
    >
      <div className="mx-auto max-w-7xl px-4 sm:px-6 xl:px-8">
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
      </div>
    </section>
  );
}
