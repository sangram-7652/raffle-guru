import type { Metadata } from "next";
import { Container } from "@/components/layout";
import OrderConfirmation from "@/components/checkout/OrderConfirmation";

export const metadata: Metadata = {
  title: "Order confirmed",
  description: "Your competition entries are confirmed.",
};

export default function OrderSuccessPage() {
  return (
    <Container className="py-14 lg:py-20">
      <OrderConfirmation />
    </Container>
  );
}
