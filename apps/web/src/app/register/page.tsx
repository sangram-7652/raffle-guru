import type { Metadata } from "next";
import Link from "next/link";
import { Container } from "@/components/layout";
import RegisterForm from "@/components/auth/RegisterForm";
import { routes } from "@/lib/routes";

export const metadata: Metadata = {
  title: "Create an account",
  description: "Create a RaffleGuru account to track your entries and results.",
};

export default function RegisterPage() {
  return (
    <Container className="flex min-h-[70vh] items-center justify-center py-16">
      <div className="w-full max-w-sm">
        <h1 className="text-center t-h2 text-heading">Create your account</h1>
        <p className="mt-2 text-center text-sm text-muted">
          Track your entries, results and account details in one place.
        </p>

        <RegisterForm />

        <p className="mt-6 text-center text-sm text-muted">
          Already have an account?{" "}
          <Link
            href={routes.login}
            className="font-semibold text-pink-700 hover:text-pink-600"
          >
            Sign in
          </Link>
        </p>
      </div>
    </Container>
  );
}
