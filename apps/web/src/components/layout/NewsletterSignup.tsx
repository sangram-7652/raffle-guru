"use client";

import { useEffect, useId, useState } from "react";
import Link from "next/link";
import Button from "../ui/Button";
import Icon from "../ui/Icon";
import Reveal from "../ui/Reveal";
import { Input, Label } from "../ui/form";
import { subscribeToNewsletter } from "@/lib/newsletter/client";
import { ApiValidationError, ApiError } from "@/lib/api/http";
import { useAuth } from "@/lib/auth/AuthContext";
import {
  FOOTER_PROMOTION_SLUG,
  claimPromotion,
  getPromotion,
  getPromotionClaim,
} from "@/lib/promotions/client";
import { routes } from "@/lib/routes";

const emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

/**
 * Footer email-capture band — rendered globally, immediately above
 * <Footer />. Two independent, honestly-separate things happen here:
 *
 * 1. Newsletter subscription (always available to a guest) — real,
 *    POSTs to /v1/newsletter/subscribe.
 * 2. The "free ticket" claim — only ever offered once a Promotion
 *    exists, is open, and the visitor is a logged-in, verified
 *    account (see docs/EMAIL_CAPTURE.md "Option 1 proposal" — there is
 *    no supported way to grant a ticket to an anonymous email). Until
 *    an admin configures that Promotion, `is_open` is false and this
 *    component behaves exactly like a plain subscribe form — nothing
 *    here ever claims a ticket was granted unless the backend
 *    confirms one actually was.
 */
export default function NewsletterSignup() {
  const emailId = useId();
  const errorId = useId();
  const { user, status: authStatus } = useAuth();

  const [email, setEmail] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [subscribed, setSubscribed] = useState(false);

  const [promoOpen, setPromoOpen] = useState<boolean | null>(null);
  const [claimStatus, setClaimStatus] = useState<"loading" | "none" | "fulfilled" | "failed">("loading");
  const [claiming, setClaiming] = useState(false);
  const [claimError, setClaimError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    getPromotion(FOOTER_PROMOTION_SLUG)
      .then((promotion) => {
        if (!cancelled) setPromoOpen(promotion?.is_open ?? false);
      })
      .catch(() => {
        if (!cancelled) setPromoOpen(false);
      });
    return () => {
      cancelled = true;
    };
  }, []);

  const verified = user !== null && user.email_verified_at !== null;

  useEffect(() => {
    if (!promoOpen || !verified) return;
    let cancelled = false;
    getPromotionClaim(FOOTER_PROMOTION_SLUG)
      .then((claim) => {
        if (cancelled) return;
        setClaimStatus(claim?.status === "fulfilled" ? "fulfilled" : "none");
      })
      .catch(() => {
        if (!cancelled) setClaimStatus("none");
      });
    return () => {
      cancelled = true;
    };
  }, [promoOpen, verified]);

  async function onSubscribeSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (submitting) return;
    setError(null);
    setFormError(null);

    const value = email.trim();
    if (!value) {
      setError("Enter your email address.");
      return;
    }
    if (!emailRe.test(value)) {
      setError("Enter a valid email address.");
      return;
    }

    setSubmitting(true);
    try {
      await subscribeToNewsletter({ email: value });
      setSubscribed(true);
    } catch (err) {
      if (err instanceof ApiValidationError) {
        setError(err.fieldError("email") ?? err.message);
      } else if (err instanceof ApiError) {
        setFormError(err.message);
      } else {
        setFormError("Something went wrong. Please try again.");
      }
    } finally {
      setSubmitting(false);
    }
  }

  async function onClaim() {
    if (claiming) return;
    setClaiming(true);
    setClaimError(null);
    try {
      const claim = await claimPromotion(FOOTER_PROMOTION_SLUG);
      setClaimStatus(claim.status === "fulfilled" ? "fulfilled" : "failed");
      if (claim.status !== "fulfilled") {
        setClaimError(claim.failure_reason ?? "This offer is not available.");
      }
    } catch (err) {
      if (err instanceof ApiValidationError) {
        setClaimError(err.fieldError("promotion") ?? err.message);
      } else {
        setClaimError("Something went wrong. Please try again.");
      }
      setClaimStatus("failed");
    } finally {
      setClaiming(false);
    }
  }

  const showClaimFlow = promoOpen === true && authStatus !== "loading";

  return (
    <section className="px-4 py-16 sm:px-6 lg:py-20 xl:px-8">
      <Reveal className="relative mx-auto max-w-7xl overflow-hidden rounded-hero bg-ink px-6 py-12 text-center sm:px-12 sm:py-16">
        <div className="grid-texture pointer-events-none absolute inset-0 opacity-10" />
        <div className="pointer-events-none absolute -left-16 -top-16 h-56 w-56 rounded-full bg-gold/20 blur-3xl" />
        <div className="pointer-events-none absolute -bottom-20 -right-10 h-64 w-64 rounded-full bg-gold-light/10 blur-3xl" />

        <div className="relative mx-auto max-w-xl">
          <span className="inline-flex items-center gap-2 rounded-full border border-gold/30 bg-gold/10 px-4 py-1.5 text-xs font-semibold uppercase tracking-[0.2em] text-gold">
            <Icon name="gift" className="h-3.5 w-3.5" />
            Subscriber offer
          </span>
          <h2 className="t-h1 mx-auto mt-6 text-white">Receive 1 Free Ticket &amp; Entry</h2>
          <p className="mx-auto mt-4 max-w-md text-white/70">
            Enter your email and never miss a competition.
          </p>

          {showClaimFlow && user && verified ? (
            <ClaimPanel
              status={claimStatus}
              claiming={claiming}
              error={claimError}
              onClaim={onClaim}
            />
          ) : showClaimFlow && user && !verified ? (
            <VerifyToClaimPanel />
          ) : subscribed ? (
            <SubscribedPanel showClaimHint={promoOpen === true} />
          ) : (
            <SubscribeForm
              email={email}
              setEmail={setEmail}
              error={error}
              formError={formError}
              submitting={submitting}
              emailId={emailId}
              errorId={errorId}
              onSubmit={onSubscribeSubmit}
            />
          )}
        </div>
      </Reveal>
    </section>
  );
}

function SubscribeForm({
  email,
  setEmail,
  error,
  formError,
  submitting,
  emailId,
  errorId,
  onSubmit,
}: {
  email: string;
  setEmail: (v: string) => void;
  error: string | null;
  formError: string | null;
  submitting: boolean;
  emailId: string;
  errorId: string;
  onSubmit: (e: React.FormEvent) => void;
}) {
  return (
    <form noValidate onSubmit={onSubmit} className="mt-8">
      <div className="mx-auto flex max-w-md flex-col gap-3 sm:flex-row sm:items-start">
        <div className="flex-1 text-left">
          <Label htmlFor={emailId} className="sr-only">
            Email address
          </Label>
          <Input
            id={emailId}
            type="email"
            inputMode="email"
            autoComplete="email"
            placeholder="Enter your email address"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            state={error ? "error" : "default"}
            aria-invalid={error ? true : undefined}
            aria-describedby={error ? errorId : undefined}
            disabled={submitting}
          />
          {error && (
            <p id={errorId} role="alert" className="mt-1.5 text-left t-caption font-medium text-error">
              {error}
            </p>
          )}
        </div>
        <Button type="submit" size="lg" loading={submitting} disabled={submitting}>
          Claim Your Free Entry
          <Icon name="arrow" className="h-4 w-4" />
        </Button>
      </div>

      {formError && (
        <p role="alert" className="mt-3 text-center t-caption font-medium text-error">
          {formError}
        </p>
      )}

      <p className="mt-4 text-center text-xs text-white/50">
        We&apos;ll only email you about new competitions. Unsubscribe anytime.
      </p>
    </form>
  );
}

function SubscribedPanel({ showClaimHint }: { showClaimHint: boolean }) {
  return (
    <div
      role="status"
      className="mx-auto mt-8 flex max-w-sm flex-col items-center gap-3 rounded-card border border-success/30 bg-success/10 px-6 py-6"
    >
      <span className="grid h-10 w-10 place-items-center rounded-full bg-success-700 text-white">
        <Icon name="check" className="h-5 w-5" />
      </span>
      <p className="t-body-sm text-white/80">
        You&apos;re on the list — keep an eye on your inbox for new competitions.
      </p>
      {showClaimHint && (
        <p className="t-body-sm text-white/80">
          <Link href={routes.login} className="font-semibold text-gold underline-offset-2 hover:underline">
            Log in
          </Link>{" "}
          or{" "}
          <Link href={routes.register} className="font-semibold text-gold underline-offset-2 hover:underline">
            create a free account
          </Link>{" "}
          to claim your ticket.
        </p>
      )}
    </div>
  );
}

function VerifyToClaimPanel() {
  return (
    <div className="mx-auto mt-8 flex max-w-sm flex-col items-center gap-3 rounded-card border border-gold/30 bg-gold/10 px-6 py-6">
      <Icon name="lock" className="h-6 w-6 text-gold" />
      <p className="t-body-sm text-white/80">
        Verify your email to claim your free ticket.
      </p>
      <Button href={routes.verifyEmail} size="sm">
        Verify email
      </Button>
    </div>
  );
}

function ClaimPanel({
  status,
  claiming,
  error,
  onClaim,
}: {
  status: "loading" | "none" | "fulfilled" | "failed";
  claiming: boolean;
  error: string | null;
  onClaim: () => void;
}) {
  if (status === "fulfilled") {
    return (
      <div
        role="status"
        className="mx-auto mt-8 flex max-w-sm flex-col items-center gap-3 rounded-card border border-success/30 bg-success/10 px-6 py-6"
      >
        <span className="grid h-10 w-10 place-items-center rounded-full bg-success-700 text-white">
          <Icon name="check" className="h-5 w-5" />
        </span>
        <p className="t-body-sm text-white/80">
          You&apos;ve claimed your free ticket — check your account entries.
        </p>
        <Button href={routes.accountEntries} size="sm">
          View your entries
        </Button>
      </div>
    );
  }

  return (
    <div className="mt-8">
      <Button
        type="button"
        size="lg"
        loading={claiming || status === "loading"}
        disabled={claiming || status === "loading"}
        onClick={onClaim}
      >
        Claim Your Free Entry
        <Icon name="arrow" className="h-4 w-4" />
      </Button>
      {error && (
        <p role="alert" className="mt-3 text-center t-caption font-medium text-error">
          {error}
        </p>
      )}
    </div>
  );
}
