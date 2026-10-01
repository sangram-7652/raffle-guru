import type { Metadata } from "next";
import { PageHeader, Section, SectionHeading } from "@/components/layout";
import { Accordion, Button, Icon } from "@/components/ui";
import { hiwSteps, drawFlow, hiwFaqPreview } from "@/data/howItWorks";
import { routes } from "@/lib/routes";

export const metadata: Metadata = {
  title: "How It Works",
  description:
    "Choose, enter, watch, win. How RaffleGuru competitions work — from picking a competition to the prize arriving.",
};

export default function HowItWorksPage() {
  return (
    <>
      <PageHeader
        eyebrow="How it works"
        decorated
        title="Four steps to your next win"
        lede="No queues, no catch. Every competition has published odds, a fixed draw date and a live, verified draw. Here's exactly how it works."
        breadcrumbs={[
          { label: "Home", href: routes.home },
          { label: "How It Works" },
        ]}
        actions={
          <Button href={routes.competitions} size="lg">
            Browse competitions
            <Icon name="arrow" className="h-4 w-4" />
          </Button>
        }
      />

      <Section>
        <ol className="grid gap-6 lg:grid-cols-2">
          {hiwSteps.map((step) => (
            <li
              key={step.key}
              id={step.key}
              className="scroll-mt-24 rounded-card border border-line bg-card p-6 card-shadow sm:p-8"
            >
              <div className="flex items-center gap-4">
                <span
                  aria-hidden
                  className="font-display text-4xl font-extrabold leading-none text-muted/25"
                >
                  {step.number}
                </span>
                <span className="grid h-11 w-11 place-items-center rounded-2xl border border-line bg-panel text-gold">
                  <Icon name={step.icon} className="h-5 w-5" />
                </span>
                <h2 className="font-display text-xl font-extrabold uppercase tracking-tight text-heading">
                  {step.title}
                </h2>
              </div>
              <p className="mt-4 font-medium text-heading">{step.summary}</p>
              <div className="mt-2 space-y-3 text-sm leading-relaxed text-muted">
                {step.detail.map((p, i) => (
                  <p key={i}>{p}</p>
                ))}
              </div>
            </li>
          ))}
        </ol>
      </Section>

      <Section surface="mist" bordered>
        <SectionHeading
          eyebrow="The draw process"
          title="What happens from open to payout"
          subtitle="Every competition follows the same path. Nothing is pre-recorded and nothing changes once tickets go on sale."
        />
        <ol className="mt-12 space-y-6 border-l border-line pl-6">
          {drawFlow.map((stage, i) => (
            <li key={stage.title} className="relative">
              <span className="absolute -left-[1.9rem] grid h-6 w-6 place-items-center rounded-full brand-gradient text-[11px] font-bold text-ink ring-4 ring-mist">
                {i + 1}
              </span>
              <h3 className="font-display text-base font-extrabold tracking-tight text-heading">
                {stage.title}
              </h3>
              <p className="mt-1 max-w-2xl text-sm leading-relaxed text-muted">
                {stage.body}
              </p>
            </li>
          ))}
        </ol>
      </Section>

      <Section>
        <div className="grid gap-10 lg:grid-cols-[1fr_1.4fr] lg:gap-16">
          <SectionHeading
            eyebrow="Common questions"
            title="Still wondering?"
            subtitle="The three things people ask most before their first entry."
          />
          <Accordion
            items={hiwFaqPreview.map((f, i) => ({
              id: `hiw-${i}`,
              title: f.q,
              content: <p>{f.a}</p>,
            }))}
            defaultOpen={["hiw-0"]}
          />
        </div>
        <div className="mt-10 flex flex-wrap gap-3">
          <Button href={routes.faq} variant="secondary">
            Read the full FAQ
          </Button>
          <Button href={routes.competitionRules} variant="ghost">
            Competition Rules
            <Icon name="arrow" className="h-4 w-4" />
          </Button>
        </div>
      </Section>
    </>
  );
}
