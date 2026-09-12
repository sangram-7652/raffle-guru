import type { Metadata } from "next";
import { Container, PageHeader } from "@/components/layout";
import CartView from "@/components/cart/CartView";
import { routes } from "@/lib/routes";

export const metadata: Metadata = {
  title: "Your basket",
  description: "Review your competition entries before checkout.",
};

export default function CartPage() {
  return (
    <>
      <PageHeader
        eyebrow="Basket"
        title="Your entries"
        lede="Review your entries and adjust quantities before checkout."
        breadcrumbs={[
          { label: "Home", href: routes.home },
          { label: "Competitions", href: routes.competitions },
          { label: "Basket" },
        ]}
      />
      <Container className="py-12 lg:py-16">
        <CartView />
      </Container>
    </>
  );
}
