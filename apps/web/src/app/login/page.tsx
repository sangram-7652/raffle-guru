import type { Metadata } from "next";
import Link from "next/link";
import { Container } from "@/components/layout";
import Button from "@/components/ui/Button";
import Icon from "@/components/ui/Icon";
import { FormField, Input } from "@/components/ui/form";
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

        <form
          aria-describedby="login-note"
          className="mt-8 rounded-card border border-line bg-card p-6 card-shadow"
        >
          <fieldset disabled className="space-y-4">
            <FormField label="Email">
              <Input type="email" placeholder="you@example.com" />
            </FormField>
            <FormField label="Password">
              <Input type="password" placeholder="••••••••" />
            </FormField>
            <Button type="submit" block size="lg">
              Sign in
            </Button>
          </fieldset>
          <p
            id="login-note"
            className="mt-4 flex items-start gap-2 text-xs text-muted"
          >
            <Icon name="lock" className="mt-0.5 h-3.5 w-3.5 shrink-0" />
            Accounts and authentication are not part of this prototype. This is a
            layout placeholder only.
          </p>
        </form>

        <p className="mt-6 text-center text-sm text-muted">
          New to RaffleGuru?{" "}
          <Link
            href={routes.competitions}
            className="font-semibold text-pink-700 hover:text-pink-600"
          >
            Browse competitions
          </Link>
        </p>
      </div>
    </Container>
  );
}
