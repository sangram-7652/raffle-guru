import type { Metadata } from "next";
import { Container, PageHeader } from "@/components/layout";
import CompetitionListing from "@/components/competition/CompetitionListing";
import { competitions } from "@/data/competitions";
import { routes } from "@/lib/routes";

export const metadata: Metadata = {
  title: "Competitions",
  description:
    "Every live RaffleGuru competition — cars, tax-free cash, luxury watches, tech and trips. Filter by category, sort by draw date or price.",
};

export default async function CompetitionsPage({
  searchParams,
}: {
  searchParams: Promise<{ category?: string }>;
}) {
  const { category } = await searchParams;

  return (
    <>
      <PageHeader
        eyebrow="Competitions"
        title="Every live competition"
        lede="Fixed entry caps, published draw dates and a verified live draw for every prize. Filter by what you actually want."
        breadcrumbs={[
          { label: "Home", href: routes.home },
          { label: "Competitions" },
        ]}
      />

      <Container className="py-12 lg:py-16">
        <CompetitionListing
          competitions={competitions}
          initialCategory={category ?? "All"}
        />
      </Container>
    </>
  );
}
