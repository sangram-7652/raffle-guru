import Icon from "./Icon";
import Reveal from "./Reveal";
import SectionHeading from "./SectionHeading";
import { whyChooseUs } from "@/data/homepageMockData";

export default function WhyChooseUs() {
  return (
    <section
      id="why"
      className="mx-auto max-w-7xl scroll-mt-24 px-4 py-20 sm:px-6 lg:py-28 xl:px-8"
    >
      <SectionHeading
        eyebrow="Why Raffle Guru"
        title="Why play with RaffleGuru?"
        subtitle="A premium competition experience built on fairness, clarity and genuine excitement."
        align="center"
        className="mx-auto"
      />

      <div className="mt-14 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
        {whyChooseUs.map((feature, i) => (
          <Reveal key={feature.title} delay={i * 80} className="h-full">
            <div className="group relative h-full overflow-hidden rounded-card border border-line bg-card p-6 card-shadow transition-all duration-300 hover:-translate-y-1 hover:border-pink/40">
              <div className="pointer-events-none absolute -right-10 -top-10 h-28 w-28 rounded-full brand-gradient opacity-0 blur-2xl transition-opacity duration-500 group-hover:opacity-30" />
              <span className="relative grid h-12 w-12 place-items-center rounded-2xl border border-line bg-panel text-pink-600 transition-colors duration-300 group-hover:border-pink/50 group-hover:text-pink">
                <Icon name={feature.icon} className="h-5 w-5" />
              </span>
              <h3 className="relative mt-5 font-display text-base font-extrabold uppercase tracking-tight text-heading">
                {feature.title}
              </h3>
              <p className="relative mt-2 text-sm leading-relaxed text-muted">
                {feature.copy}
              </p>
            </div>
          </Reveal>
        ))}
      </div>
    </section>
  );
}
