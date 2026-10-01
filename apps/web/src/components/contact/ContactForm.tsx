"use client";

import { useState } from "react";
import Button from "../ui/Button";
import Icon from "../ui/Icon";
import { FormField, Input, Select, Textarea } from "../ui/form";
import { contactSubjects } from "@/data/site";

type Errors = Partial<Record<"name" | "email" | "subject" | "message", string>>;

const emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

export default function ContactForm() {
  const [values, setValues] = useState({
    name: "",
    email: "",
    subject: "",
    message: "",
  });
  const [errors, setErrors] = useState<Errors>({});
  const [submitted, setSubmitted] = useState(false);

  const set = (key: keyof typeof values) => (v: string) => {
    setValues((prev) => ({ ...prev, [key]: v }));
    setErrors((prev) => ({ ...prev, [key]: undefined }));
  };

  function validate(): Errors {
    const next: Errors = {};
    if (!values.name.trim()) next.name = "Please enter your name.";
    if (!values.email.trim()) next.email = "Please enter your email address.";
    else if (!emailRe.test(values.email.trim()))
      next.email = "That doesn't look like a valid email address.";
    if (!values.subject) next.subject = "Please choose a subject.";
    if (values.message.trim().length < 10)
      next.message = "Please give us a little more detail (10+ characters).";
    return next;
  }

  function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    const next = validate();
    setErrors(next);
    if (Object.keys(next).length === 0) {
      // Frontend-only: no email is sent. Show the success state.
      setSubmitted(true);
    } else {
      const first = document.querySelector<HTMLElement>('[aria-invalid="true"]');
      first?.focus();
    }
  }

  if (submitted) {
    return (
      <div
        role="status"
        className="flex flex-col items-center rounded-card border border-success/30 bg-success/10 px-6 py-12 text-center"
      >
        <span className="grid h-14 w-14 place-items-center rounded-full bg-success-700 text-white">
          <Icon name="check" className="h-6 w-6" />
        </span>
        <h2 className="mt-5 font-display text-xl font-extrabold tracking-tight text-heading">
          Message sent
        </h2>
        <p className="mt-2 max-w-sm text-sm text-muted">
          Thanks {values.name.split(" ")[0] || "there"} — we&apos;ve got your
          message and aim to reply within one working day. (This is a prototype,
          so nothing was actually emailed.)
        </p>
        <Button
          variant="secondary"
          className="mt-6"
          onClick={() => {
            setSubmitted(false);
            setValues({ name: "", email: "", subject: "", message: "" });
          }}
        >
          Send another message
        </Button>
      </div>
    );
  }

  return (
    <form
      noValidate
      onSubmit={onSubmit}
      className="rounded-card border border-line bg-card p-6 card-shadow sm:p-8"
    >
      <div className="grid gap-5 sm:grid-cols-2">
        <FormField label="Your name" error={errors.name} required>
          <Input
            value={values.name}
            onChange={(e) => set("name")(e.target.value)}
            autoComplete="name"
            placeholder="Jordan Smith"
          />
        </FormField>
        <FormField label="Email address" error={errors.email} required>
          <Input
            type="email"
            value={values.email}
            onChange={(e) => set("email")(e.target.value)}
            autoComplete="email"
            placeholder="you@example.com"
          />
        </FormField>
      </div>

      <FormField label="Subject" error={errors.subject} required className="mt-5">
        <Select
          value={values.subject}
          onChange={(e) => set("subject")(e.target.value)}
        >
          <option value="">Choose a subject…</option>
          {contactSubjects.map((s) => (
            <option key={s} value={s}>
              {s}
            </option>
          ))}
        </Select>
      </FormField>

      <FormField
        label="Message"
        error={errors.message}
        hint="Include your order reference if you have one."
        required
        className="mt-5"
      >
        <Textarea
          rows={5}
          value={values.message}
          onChange={(e) => set("message")(e.target.value)}
          placeholder="How can we help?"
        />
      </FormField>

      <Button type="submit" size="lg" block className="mt-6">
        Send message
      </Button>
      <p className="mt-3 text-center text-xs text-muted">
        Prototype form — no message is sent and no data leaves your browser.
      </p>
    </form>
  );
}
