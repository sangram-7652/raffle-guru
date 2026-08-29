import Reveal from "./Reveal";
import SectionHeading from "./SectionHeading";
import { steps } from "@/data/homepageMockData";

export default function HowItWorks() {
  return (
    <section
      id="how-it-works"
      className="relative scroll-mt-24 overflow-hidden border-y border-line bg-mist py-20 lg:py-28"
    >
      <div className="mx-auto max-w-7xl px-4 sm:px-6 xl:px-8">
        <SectionHeading
          eyebrow="How it works"
          title="Four steps to your next win"
          subtitle="No queues, no catch. From picking a competition to the prize arriving at your door."
        />

        <ol className="card-shadow mt-14 grid gap-px overflow-hidden rounded-card border border-line bg-line sm:grid-cols-2 lg:grid-cols-4">
          {steps.map((step, i) => (
            <Reveal
              as="li"
              key={step.number}
              delay={i * 90}
              className="group relative flex flex-col gap-4 bg-card p-7 transition-colors duration-300 hover:bg-panel"
            >
              <span
                aria-hidden
                className="font-display text-6xl font-extrabold leading-none text-muted/25 transition-colors duration-300 group-hover:text-pink-600"
              >
                {step.number}
              </span>
              <div>
                <h3 className="font-display text-lg font-extrabold uppercase tracking-tight text-heading">
                  {step.title}
                </h3>
                <p className="mt-2 text-sm leading-relaxed text-muted">
                  {step.copy}
                </p>
              </div>
              <span className="mt-auto h-1 w-10 rounded-full brand-gradient opacity-40 transition-opacity duration-300 group-hover:opacity-100" />
            </Reveal>
          ))}
        </ol>
      </div>
    </section>
  );
}
