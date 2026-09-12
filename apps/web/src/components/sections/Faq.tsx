import SectionHeading from "../layout/SectionHeading";
import Reveal from "../ui/Reveal";

const faqs = [
  {
    q: "How do the competitions work?",
    a: "Pick a competition, choose how many entries you want and check out. Every competition has a set entry cap and a fixed draw date — when the timer ends, the winner is drawn live.",
  },
  {
    q: "Are the draws really live?",
    a: "Yes. Every draw is streamed on camera using a verified random draw process, and the winning ticket number is published straight afterwards.",
  },
  {
    q: "What if I'd rather have cash than the prize?",
    a: "Most physical prizes carry a cash alternative, shown on the competition. If you win, you choose which one you'd like.",
  },
  {
    q: "How quickly are prizes delivered?",
    a: "Cash prizes are transferred within 48 hours of the draw. Cars, watches and bundles are arranged directly with the winner, usually within a couple of weeks.",
  },
];

export default function Faq() {
  return (
    <section
      id="faq"
      className="mx-auto max-w-3xl scroll-mt-24 px-4 py-20 sm:px-6 lg:py-28"
    >
      <SectionHeading
        eyebrow="FAQ"
        title="Good to know"
        align="center"
        className="mx-auto"
      />

      <div className="mt-10 space-y-3">
        {faqs.map((item, i) => (
          <Reveal key={item.q} delay={i * 60}>
            <details className="group rounded-2xl border border-line bg-card px-5 open:border-pink/30">
              <summary className="flex cursor-pointer list-none items-center justify-between gap-4 py-4 font-display text-base font-bold tracking-tight text-heading marker:hidden">
                {item.q}
                <span className="grid h-6 w-6 shrink-0 place-items-center rounded-full border border-line text-pink-600 transition-transform duration-300 group-open:rotate-45">
                  +
                </span>
              </summary>
              <p className="pb-5 text-sm leading-relaxed text-muted">{item.a}</p>
            </details>
          </Reveal>
        ))}
      </div>
    </section>
  );
}
