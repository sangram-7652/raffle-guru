import type { Metadata } from "next";
import { Suspense } from "react";
import { Container } from "@/components/layout";
import VerifyEmailStatus from "@/components/auth/VerifyEmailStatus";

export const metadata: Metadata = {
  title: "Verify email",
  description: "Verify your RaffleGuru account email address.",
};

export default function VerifyEmailPage() {
  return (
    <Container className="flex min-h-[70vh] items-center justify-center py-16">
      <div className="w-full max-w-sm">
        <Suspense>
          <VerifyEmailStatus />
        </Suspense>
      </div>
    </Container>
  );
}
