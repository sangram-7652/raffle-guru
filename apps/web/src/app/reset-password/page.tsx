import type { Metadata } from "next";
import { Suspense } from "react";
import { Container } from "@/components/layout";
import ResetPasswordForm from "@/components/auth/ResetPasswordForm";

export const metadata: Metadata = {
  title: "Reset password",
  description: "Choose a new password for your RaffleGuru account.",
};

export default function ResetPasswordPage() {
  return (
    <Container className="flex min-h-[70vh] items-center justify-center py-16">
      <div className="w-full max-w-sm">
        <h1 className="text-center t-h2 text-heading">Reset your password</h1>
        <p className="mt-2 text-center text-sm text-muted">
          Choose a new password below.
        </p>

        <Suspense>
          <ResetPasswordForm />
        </Suspense>
      </div>
    </Container>
  );
}
