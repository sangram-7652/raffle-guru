import type { Metadata } from "next";
import Link from "next/link";
import { Container } from "@/components/layout";
import ForgotPasswordForm from "@/components/auth/ForgotPasswordForm";
import { routes } from "@/lib/routes";

export const metadata: Metadata = {
  title: "Forgot password",
  description: "Reset your RaffleGuru account password.",
};

export default function ForgotPasswordPage() {
  return (
    <Container className="flex min-h-[70vh] items-center justify-center py-16">
      <div className="w-full max-w-sm">
        <h1 className="text-center t-h2 text-heading">Forgot your password?</h1>
        <p className="mt-2 text-center text-sm text-muted">
          Enter your email and we&apos;ll send you a link to reset it.
        </p>

        <ForgotPasswordForm />

        <p className="mt-6 text-center text-sm text-muted">
          Remembered it after all?{" "}
          <Link
            href={routes.login}
            className="font-semibold text-gold-700 hover:text-gold"
          >
            Sign in
          </Link>
        </p>
      </div>
    </Container>
  );
}
