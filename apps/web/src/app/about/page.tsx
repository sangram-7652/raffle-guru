import type { Metadata } from "next";
import { PageHeader, Section, SectionHeading } from "@/components/layout";
import { Button, Icon, StatCard } from "@/components/ui";
import ImageFrame from "@/components/media/ImageFrame";
import { brandValues, companyStats } from "@/data/site";
import { routes } from "@/lib/routes";

export const metadata: Metadata = {
  title: "About",
  description:
    "RaffleGuru is a premium UK competition platform built on transparent live draws, published odds and prizes people actually want.",
};

const timeline = [
  {
    year: "The idea",
    body: "Competition sites were everywhere, but most felt murky — unclear odds, draws you couldn't really see. We wanted one that was obviously fair.",
  },
  {
    year: "The build",
    body: "Fixed entry caps on every competition, a verified random draw, and a livestream for each result. No unlimited tickets, no exceptions.",
  },
  {
    year: "Today",
    body: "Headline draws every Sunday, midweek cash draws, and thousands of prizes handed over — with the photos and ticket numbers to prove it.",
  },
];

export default function AboutPage() {
  return (
    <>
      <PageHeader
        eyebrow="Our story"
        decorated
        title="A competition platform you can actually trust"
        lede="RaffleGuru is a premium UK prize competition platform. We run genuinely exciting competitions with clear odds, verified live draws and prizes people really want — cars, tax-free cash, luxury watches and once-in-a-lifetime trips."
        breadcrumbs={[{ label: "Home", href: routes.home }, { label: "About" }]}
        actions={
          <Button href={routes.competitions} size="lg">
            See what&apos;s live
            <Icon name="arrow" className="h-4 w-4" />
          </Button>
        }
      />

      <Section>
        <div className="grid items-center gap-10 lg:grid-cols-2 lg:gap-16">
          <ImageFrame
            src="https://images.unsplash.com/photo-1511578314322-379afb476865?auto=format&fit=crop&w=1200&q=80"
            alt="The RaffleGuru live draw studio"
            ratio="4/3"
            radius="panel"
            className="card-shadow-lg"
          />
          <div>
            <SectionHeading
              eyebrow="Our mission"
              title="Make every draw impossible to doubt"
            />
            <div className="mt-5 space-y-4 t-body text-muted">
              <p>
                Every competition we run has a published maximum number of
                tickets, so you know your odds before you enter. When the timer
                ends, the winner is drawn live on camera using a verified random
                number generator — and the recording stays online for anyone to
                check.
              </p>
              <p>
                We think that&apos;s how all of this should work. Clear pricing,
                clear odds, and a result you can watch happen.
              </p>
            </div>
          </div>
        </div>
      </Section>

      <Section surface="mist" bordered>
        <SectionHeading
          align="center"
          className="mx-auto"
          eyebrow="What we stand for"
          title="Four things we don't compromise on"
        />
        <div className="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
          {brandValues.map((v) => (
            <div
              key={v.title}
              className="rounded-card border border-line bg-card p-6 card-shadow"
            >
              <span className="grid h-12 w-12 place-items-center rounded-2xl border border-line bg-panel text-gold-700">
                <Icon name={v.icon} className="h-5 w-5" />
              </span>
              <h3 className="mt-5 font-display text-base font-extrabold uppercase tracking-tight text-heading">
                {v.title}
              </h3>
              <p className="mt-2 text-sm leading-relaxed text-muted">{v.copy}</p>
            </div>
          ))}
        </div>
      </Section>

      <Section>
        <SectionHeading eyebrow="How we got here" title="The short version" />
        <ol className="mt-10 space-y-6 border-l border-line pl-6">
          {timeline.map((t) => (
            <li key={t.year} className="relative">
              <span className="absolute -left-[1.65rem] top-1 h-3 w-3 rounded-full brand-gradient ring-4 ring-paper" />
              <p className="font-display text-sm font-bold uppercase tracking-wider text-gold-700">
                {t.year}
              </p>
              <p className="mt-1 max-w-2xl t-body text-muted">{t.body}</p>
            </li>
          ))}
        </ol>
      </Section>

      <Section surface="mist" bordered spaceY="sm">
        <dl className="grid grid-cols-2 gap-6 lg:grid-cols-4">
          {companyStats.map((s) => (
            <StatCard key={s.label} value={s.value} label={s.label} />
          ))}
        </dl>
      </Section>

      <Section>
        <div className="relative overflow-hidden rounded-hero bg-ink px-6 py-14 text-center sm:px-12 sm:py-16">
          <div className="grid-texture pointer-events-none absolute inset-0 opacity-10" />
          <div className="relative">
            <h2 className="t-h2 mx-auto max-w-xl text-white">
              Ready to find your competition?
            </h2>
            <p className="mx-auto mt-3 max-w-md text-white/75">
              New draws every week, entries from under £1.
            </p>
            <div className="mt-7 flex flex-wrap justify-center gap-3">
              <Button href={routes.competitions} size="lg">
                Browse competitions
              </Button>
              <Button
                href={routes.howItWorks}
                variant="ghost"
                size="lg"
                className="!text-white hover:!text-white/70"
              >
                How it works
              </Button>
            </div>
          </div>
        </div>
      </Section>
    </>
  );
}
