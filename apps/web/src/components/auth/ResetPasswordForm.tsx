"use client";

import { useState } from "react";
import { useSearchParams } from "next/navigation";
import Button from "@/components/ui/Button";
import { FormField, Input } from "@/components/ui/form";
import EmptyState from "@/components/ui/EmptyState";
import { resetPassword, ValidationError, AuthError } from "@/lib/auth/client";
import { routes } from "@/lib/routes";

export default function ResetPasswordForm() {
  const searchParams = useSearchParams();
  const token = searchParams.get("token") ?? "";
  const email = searchParams.get("email") ?? "";

  const [password, setPassword] = useState("");
  const [confirmation, setConfirmation] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [done, setDone] = useState(false);

  if (!token || !email) {
    return (
      <EmptyState
        icon="clock"
        title="Invalid or expired link"
        description="This password reset link is missing required information. Request a new one."
        action={
          <Button href={routes.forgotPassword} size="sm">
            Request new link
          </Button>
        }
      />
    );
  }

  if (done) {
    return (
      <div className="mt-8 rounded-card border border-line bg-card p-6 card-shadow">
        <p className="t-body text-heading">Your password has been reset.</p>
        <Button href={routes.login} block size="lg" className="mt-4">
          Sign in
        </Button>
      </div>
    );
  }

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setFormError(null);

    if (!password || password.length < 8) {
      setError("Password must be at least 8 characters.");
      return;
    }
    if (password !== confirmation) {
      setError("Passwords do not match.");
      return;
    }

    setSubmitting(true);
    try {
      await resetPassword({
        token,
        email,
        password,
        password_confirmation: confirmation,
      });
      setDone(true);
    } catch (err) {
      if (err instanceof ValidationError) {
        setError(err.fieldError("password") ?? err.fieldError("email") ?? err.message);
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
        <FormField label="New password" error={error} hint="At least 8 characters.">
          <Input
            type="password"
            placeholder="••••••••"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            autoComplete="new-password"
          />
        </FormField>
        <FormField label="Confirm new password">
          <Input
            type="password"
            placeholder="••••••••"
            value={confirmation}
            onChange={(e) => setConfirmation(e.target.value)}
            autoComplete="new-password"
          />
        </FormField>

        {formError && (
          <p role="alert" className="t-caption font-medium text-error-600">
            {formError}
          </p>
        )}

        <Button type="submit" block size="lg" loading={submitting} disabled={submitting}>
          Reset password
        </Button>
      </div>
    </form>
  );
}
