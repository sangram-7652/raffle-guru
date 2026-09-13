"use client";

import { useState } from "react";
import Button from "@/components/ui/Button";
import { FormField, Input } from "@/components/ui/form";
import { forgotPassword, ValidationError, AuthError } from "@/lib/auth/client";

const emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

export default function ForgotPasswordForm() {
  const [email, setEmail] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  // Deliberately generic: shown for both a known and unknown email, so this
  // screen never confirms whether an account exists for a given address.
  const [sent, setSent] = useState(false);

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setFormError(null);

    if (!email.trim()) {
      setError("Enter your email address.");
      return;
    }
    if (!emailRe.test(email)) {
      setError("Enter a valid email address.");
      return;
    }

    setSubmitting(true);
    try {
      await forgotPassword({ email });
      setSent(true);
    } catch (err) {
      if (err instanceof ValidationError) {
        setError(err.fieldError("email") ?? err.message);
      } else if (err instanceof AuthError) {
        setFormError(err.message);
      } else {
        setFormError("Something went wrong. Please try again.");
      }
    } finally {
      setSubmitting(false);
    }
  }

  if (sent) {
    return (
      <div className="mt-8 rounded-card border border-line bg-card p-6 card-shadow">
        <p className="t-body text-heading">
          If an account exists for <strong>{email}</strong>, we&apos;ve sent a link to
          reset the password. It expires in 60 minutes.
        </p>
      </div>
    );
  }

  return (
    <form
      onSubmit={onSubmit}
      noValidate
      className="mt-8 rounded-card border border-line bg-card p-6 card-shadow"
    >
      <div className="space-y-4">
        <FormField label="Email" error={error}>
          <Input
            type="email"
            placeholder="you@example.com"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            autoComplete="email"
          />
        </FormField>

        {formError && (
          <p role="alert" className="t-caption font-medium text-error-600">
            {formError}
          </p>
        )}

        <Button type="submit" block size="lg" loading={submitting} disabled={submitting}>
          Send reset link
        </Button>
      </div>
    </form>
  );
}
