import type { Metadata } from "next";
import { Container, PageHeader } from "@/components/layout";
import { Button } from "@/components/ui";
import FaqBrowser from "@/components/faq/FaqBrowser";
import { routes } from "@/lib/routes";

export const metadata: Metadata = {
  title: "FAQ",
  description:
    "Answers about RaffleGuru competitions, entries, payments, live draws, winners and prizes.",
};

export default function FaqPage() {
  return (
    <>
      <PageHeader
        eyebrow="FAQ"
        title="Frequently asked questions"
        lede="Everything about entering, the draws, winning and getting your prize. Can't find it? Our team is one message away."
        breadcrumbs={[{ label: "Home", href: routes.home }, { label: "FAQ" }]}
      />

      <Container className="py-12 lg:py-16">
        <FaqBrowser />

        <div className="mt-12 rounded-card border border-line bg-mist p-6 text-center">
          <p className="font-display text-lg font-extrabold tracking-tight text-heading">
            Still need a hand?
          </p>
          <p className="mx-auto mt-2 max-w-md text-sm text-muted">
            Our support team replies within one working day.
          </p>
          <Button href={routes.contact} className="mt-4">
            Contact us
          </Button>
        </div>
      </Container>
    </>
  );
}
