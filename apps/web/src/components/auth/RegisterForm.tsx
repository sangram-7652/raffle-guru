"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import Button from "@/components/ui/Button";
import { FormField, Input } from "@/components/ui/form";
import { useAuth } from "@/lib/auth/AuthContext";
import { ValidationError, AuthError } from "@/lib/auth/client";
import { routes } from "@/lib/routes";

const emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

type Values = {
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
};

const initial: Values = { name: "", email: "", password: "", password_confirmation: "" };

export default function RegisterForm() {
  const router = useRouter();
  const { register } = useAuth();

  const [values, setValues] = useState<Values>(initial);
  const [errors, setErrors] = useState<Partial<Record<keyof Values, string>>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  function set<K extends keyof Values>(key: K, value: Values[K]) {
    setValues((v) => ({ ...v, [key]: value }));
  }

  function validate(): boolean {
    const next: typeof errors = {};
    if (!values.name.trim()) next.name = "Enter your name.";
    if (!values.email.trim()) next.email = "Enter your email address.";
    else if (!emailRe.test(values.email)) next.email = "Enter a valid email address.";
    if (!values.password) next.password = "Enter a password.";
    else if (values.password.length < 8) next.password = "Password must be at least 8 characters.";
    else if (values.password !== values.password_confirmation) {
      next.password = "Passwords do not match.";
    }
    setErrors(next);
    return Object.keys(next).length === 0;
  }

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    setFormError(null);
    if (!validate()) return;

    setSubmitting(true);
    try {
      await register(values);
      router.push(routes.account);
    } catch (err) {
      if (err instanceof ValidationError) {
        setErrors({
          name: err.fieldError("name"),
          email: err.fieldError("email"),
          password: err.fieldError("password"),
        });
        setFormError(err.errors.email || err.errors.password ? null : err.message);
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
        <FormField label="Name" error={errors.name}>
          <Input
            type="text"
            placeholder="Jane Smith"
            value={values.name}
            onChange={(e) => set("name", e.target.value)}
            autoComplete="name"
          />
        </FormField>
        <FormField label="Email" error={errors.email}>
          <Input
            type="email"
            placeholder="you@example.com"
            value={values.email}
            onChange={(e) => set("email", e.target.value)}
            autoComplete="email"
          />
        </FormField>
        <FormField label="Password" error={errors.password} hint="At least 8 characters.">
          <Input
            type="password"
            placeholder="••••••••"
            value={values.password}
            onChange={(e) => set("password", e.target.value)}
            autoComplete="new-password"
          />
        </FormField>
        <FormField label="Confirm password">
          <Input
            type="password"
            placeholder="••••••••"
            value={values.password_confirmation}
            onChange={(e) => set("password_confirmation", e.target.value)}
            autoComplete="new-password"
          />
        </FormField>

        {formError && (
          <p role="alert" className="t-caption font-medium text-error-600">
            {formError}
          </p>
        )}

        <Button type="submit" block size="lg" loading={submitting} disabled={submitting}>
          Create account
        </Button>
      </div>
    </form>
  );
}
