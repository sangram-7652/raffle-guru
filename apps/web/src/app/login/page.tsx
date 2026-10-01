import type { Metadata } from "next";
import { Suspense } from "react";
import Link from "next/link";
import { Container } from "@/components/layout";
import LoginForm from "@/components/auth/LoginForm";
import { routes } from "@/lib/routes";

export const metadata: Metadata = {
  title: "Login",
  description: "Sign in to your RaffleGuru account.",
};

export default function LoginPage() {
  return (
    <Container className="flex min-h-[70vh] items-center justify-center py-16">
      <div className="w-full max-w-sm">
        <h1 className="text-center t-h2 text-heading">Welcome back</h1>
        <p className="mt-2 text-center text-sm text-muted">
          Sign in to see your entries and results.
        </p>

        <Suspense>
          <LoginForm />
        </Suspense>

        <p className="mt-6 text-center text-sm text-muted">
          New to RaffleGuru?{" "}
          <Link
            href={routes.register}
            className="font-semibold text-gold-700 hover:text-gold"
          >
            Create an account
          </Link>
        </p>
      </div>
    </Container>
  );
}
