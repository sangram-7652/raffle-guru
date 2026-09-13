import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { Container } from "@/components/layout";
import Breadcrumbs from "@/components/layout/Breadcrumbs";
import Badge from "@/components/ui/Badge";
import CompetitionGallery from "@/components/competition/CompetitionGallery";
import CompetitionFacts from "@/components/competition/CompetitionFacts";
import CompetitionInfo from "@/components/competition/CompetitionInfo";
import EntrySelector from "@/components/competition/EntrySelector";
import StickyEnterBar from "@/components/competition/StickyEnterBar";
import RelatedCompetitions from "@/components/competition/RelatedCompetitions";
import { adaptCompetition } from "@/lib/competitions/adapter";
import { getCompetition, listCompetitions } from "@/lib/competitions/client";
import { routes } from "@/lib/routes";

// Competitions are now real, changeable data (not a fixed mock set) —
// rendered dynamically per-request rather than pre-generated at build
// time, so a competition added after the last build still has a page.

export async function generateMetadata({
  params,
}: {
  params: Promise<{ slug: string }>;
}): Promise<Metadata> {
  const { slug } = await params;
  const competition = await getCompetition(slug);
  if (!competition) return { title: "Competition not found" };
  return {
    title: competition.title,
    description: competition.short_description,
  };
}

const statusBadge = {
  live: { tone: "live" as const, label: "Live" },
  "ending-soon": { tone: "ending-soon" as const, label: "Ending soon" },
  "sold-out": { tone: "sold-out" as const, label: "Sold out" },
  drawn: { tone: "neutral" as const, label: "Drawn" },
};

export default async function CompetitionDetailPage({
  params,
}: {
  params: Promise<{ slug: string }>;
}) {
  const { slug } = await params;
  const apiCompetition = await getCompetition(slug);
  if (!apiCompetition) notFound();

  const competition = adaptCompetition(apiCompetition);
  const badge = statusBadge[competition.status];

  const relatedResponse = await listCompetitions({
    category: competition.category,
    per_page: 4,
  });
  const related = relatedResponse.data
    .filter((c) => c.slug !== slug)
    .slice(0, 3)
    .map(adaptCompetition);

  return (
    <>
      <div className="border-b border-line bg-mist">
        <Container className="py-5">
          <Breadcrumbs
            items={[
              { label: "Home", href: routes.home },
              { label: "Competitions", href: routes.competitions },
              { label: competition.title },
            ]}
          />
        </Container>
      </div>

      <Container className="py-8 pb-28 lg:py-12 lg:pb-16">
        <div className="grid gap-10 lg:grid-cols-[minmax(0,1fr)_23rem] lg:gap-12 xl:grid-cols-[minmax(0,1fr)_26rem]">
          {/* Main column */}
          <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-2">
              <Badge tone={badge.tone}>{badge.label}</Badge>
              <Badge
                tone="neutral"
                icon={
                  <span className="text-pink-600" aria-hidden>
                    ●
                  </span>
                }
              >
                {competition.category}
              </Badge>
            </div>

            <h1 className="mt-3 t-h1 text-heading">{competition.title}</h1>
            <p className="mt-2 max-w-2xl t-body-lg text-muted">
              {competition.blurb}
            </p>

            <div className="mt-6">
              <CompetitionGallery
                images={competition.images}
                title={competition.title}
              />
            </div>

            <div className="mt-6">
              <CompetitionFacts competition={competition} />
            </div>

            {/* Entry panel on mobile sits here, in flow */}
            <div className="mt-8 lg:hidden">
              <EntrySelector competition={competition} domId="enter" />
            </div>

            <div className="mt-10">
              <h2 className="sr-only">Competition information</h2>
              <CompetitionInfo competition={competition} />
            </div>
          </div>

          {/* Sidebar (desktop) */}
          <aside className="hidden lg:block">
            <div className="lg:sticky lg:top-24">
              <EntrySelector competition={competition} domId="enter-desktop" />
            </div>
          </aside>
        </div>

        <div className="mt-16">
          <RelatedCompetitions competitions={related} />
        </div>
      </Container>

      <StickyEnterBar competition={competition} />
    </>
  );
}
