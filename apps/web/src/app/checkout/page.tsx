import type { Metadata } from "next";
import { Container, PageHeader } from "@/components/layout";
import CheckoutView from "@/components/checkout/CheckoutView";
import { routes } from "@/lib/routes";

export const metadata: Metadata = {
  title: "Checkout",
  description: "Confirm your details and place your entry order.",
};

export default function CheckoutPage() {
  return (
    <>
      <PageHeader
        eyebrow="Checkout"
        title="Confirm your entries"
        lede="Enter your details to complete your order. No payment is taken yet — payment isn't connected until a later phase."
        breadcrumbs={[
          { label: "Home", href: routes.home },
          { label: "Basket", href: routes.cart },
          { label: "Checkout" },
        ]}
      />
      <Container className="py-12 lg:py-16">
        <CheckoutView />
      </Container>
    </>
  );
}
