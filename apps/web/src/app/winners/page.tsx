import type { Metadata } from "next";
import { Container, PageHeader, Section, SectionHeading } from "@/components/layout";
import { Button, Icon, StatCard } from "@/components/ui";
import WinnersFeed from "@/components/winner/WinnersFeed";
import { winners, winnerStats } from "@/data/winners";
import { routes } from "@/lib/routes";

export const metadata: Metadata = {
  title: "Winners",
  description:
    "Real RaffleGuru winners — the prize, the competition, the winning ticket number and the draw date. Sample data for this prototype.",
};

export default function WinnersPage() {
  return (
    <>
      <PageHeader
        eyebrow="Winners"
        decorated
        title="Real people. Real wins."
        lede="Every week we hand over the keys, transfer the cash and post the photos. Here's a run of recent winners — with the winning ticket number for each draw."
        breadcrumbs={[
          { label: "Home", href: routes.home },
          { label: "Winners" },
        ]}
        actions={
          <Button href={routes.competitions} size="lg">
            Enter this week&apos;s draws
            <Icon name="arrow" className="h-4 w-4" />
          </Button>
        }
      >
        <dl className="grid grid-cols-2 gap-6 border-t border-line pt-8 lg:grid-cols-4">
          {winnerStats.map((s) => (
            <StatCard key={s.label} value={s.value} label={s.label} />
          ))}
        </dl>
      </PageHeader>

      <Container className="py-12 lg:py-16">
        <WinnersFeed winners={winners} />
        <p className="mt-8 text-center text-xs text-muted">
          Winner names and photos shown here are sample data for this prototype.
        </p>
      </Container>

      <Section surface="mist" bordered>
        <div className="flex flex-col items-center gap-6 text-center">
          <SectionHeading
            align="center"
            className="mx-auto"
            eyebrow="You could be next"
            title="This week's competitions are live now"
          />
          <Button href={routes.competitions} size="lg">
            Browse competitions
            <Icon name="arrow" className="h-4 w-4" />
          </Button>
        </div>
      </Section>
    </>
  );
}
