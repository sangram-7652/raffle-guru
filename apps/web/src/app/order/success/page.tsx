import type { Metadata } from "next";
import { Suspense } from "react";
import { Container } from "@/components/layout";
import OrderConfirmation from "@/components/checkout/OrderConfirmation";

export const metadata: Metadata = {
  title: "Order created",
  description: "Your order has been created and is pending payment.",
};

export default function OrderSuccessPage() {
  return (
    <Container className="py-14 lg:py-20">
      <Suspense fallback={<div className="h-96 rounded-card border border-line bg-mist" />}>
        <OrderConfirmation />
      </Suspense>
    </Container>
  );
}
