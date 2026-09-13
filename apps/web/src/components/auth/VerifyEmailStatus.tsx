"use client";

import { useEffect, useState } from "react";
import { useSearchParams } from "next/navigation";
import Button from "@/components/ui/Button";
import EmptyState from "@/components/ui/EmptyState";
import { useAuth } from "@/lib/auth/AuthContext";
import { resendVerification } from "@/lib/auth/client";
import { routes } from "@/lib/routes";

export default function VerifyEmailStatus() {
  const searchParams = useSearchParams();
  const status = searchParams.get("status");
  const { status: authStatus, user, refresh } = useAuth();

  const [resent, setResent] = useState(false);
  const [resending, setResending] = useState(false);

  // The signed link is followed server-side (Laravel marks it verified and
  // redirects back here) — refresh the client's user object so `Header`
  // and the account page pick up the now-verified state.
  useEffect(() => {
    if (status === "success") {
      void refresh();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [status]);

  async function onResend() {
    setResending(true);
    try {
      await resendVerification();
      setResent(true);
    } finally {
      setResending(false);
    }
  }

  if (status === "success") {
    return (
      <EmptyState
        icon="trophy"
        title="Email verified"
        description="Your email address has been verified."
        action={
          <Button href={routes.account} size="sm">
            Go to your account
          </Button>
        }
      />
    );
  }

  if (status === "already-verified") {
    return (
      <EmptyState
        icon="trophy"
        title="Already verified"
        description="This email address was already verified."
        action={
          <Button href={routes.account} size="sm">
            Go to your account
          </Button>
        }
      />
    );
  }

  if (authStatus === "loading") {
    return null;
  }

  if (authStatus === "guest") {
    return (
      <EmptyState
        icon="clock"
        title="Sign in required"
        description="Sign in to verify your email address."
        action={
          <Button href={routes.login} size="sm">
            Sign in
          </Button>
        }
      />
    );
  }

  if (user?.email_verified_at) {
    return (
      <EmptyState
        icon="trophy"
        title="Already verified"
        description="Your email address is already verified."
        action={
          <Button href={routes.account} size="sm">
            Go to your account
          </Button>
        }
      />
    );
  }

  return (
    <EmptyState
      icon="clock"
      title="Verify your email"
      description={
        resent
          ? "A new verification link has been sent — check your inbox."
          : `We sent a verification link to ${user?.email}. Click it to verify your account.`
      }
      action={
        <Button size="sm" onClick={onResend} loading={resending} disabled={resending || resent}>
          Resend verification email
        </Button>
      }
    />
  );
}
