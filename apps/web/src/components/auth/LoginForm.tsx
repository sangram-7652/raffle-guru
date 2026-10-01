"use client";

import { useState } from "react";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import Button from "@/components/ui/Button";
import { FormField, Input } from "@/components/ui/form";
import { useAuth } from "@/lib/auth/AuthContext";
import { ValidationError, AuthError } from "@/lib/auth/client";
import { routes } from "@/lib/routes";

const emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

export default function LoginForm() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const { login } = useAuth();

  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [errors, setErrors] = useState<{ email?: string; password?: string }>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  function validate(): boolean {
    const next: typeof errors = {};
    if (!email.trim()) next.email = "Enter your email address.";
    else if (!emailRe.test(email)) next.email = "Enter a valid email address.";
    if (!password) next.password = "Enter your password.";
    setErrors(next);
    return Object.keys(next).length === 0;
  }

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    setFormError(null);
    if (!validate()) return;

    setSubmitting(true);
    try {
      await login({ email, password });
      const redirectTo = searchParams.get("redirect") || routes.account;
      router.push(redirectTo);
    } catch (err) {
      if (err instanceof ValidationError) {
        setFormError(err.fieldError("email") ?? err.message);
      } else if (err instanceof AuthError) {
        setFormError(err.message);
      } else {
        setFormError("Something went wrong. Please try again.");
      }
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <form
      onSubmit={onSubmit}
      noValidate
      className="mt-8 rounded-card border border-line bg-card p-6 card-shadow"
    >
      <div className="space-y-4">
        <FormField label="Email" error={errors.email}>
          <Input
            type="email"
            placeholder="you@example.com"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            autoComplete="email"
          />
        </FormField>
        <FormField label="Password" error={errors.password}>
          <Input
            type="password"
            placeholder="••••••••"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            autoComplete="current-password"
          />
        </FormField>

        {formError && (
          <p role="alert" className="t-caption font-medium text-error-600">
            {formError}
          </p>
        )}

        <div className="flex justify-end">
          <Link
            href={routes.forgotPassword}
            className="text-xs font-semibold text-gold-700 hover:text-gold-700"
          >
            Forgot password?
          </Link>
        </div>

        <Button type="submit" block size="lg" loading={submitting} disabled={submitting}>
          Sign in
        </Button>
      </div>
    </form>
  );
}
