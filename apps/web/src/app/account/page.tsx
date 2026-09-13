"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { Container, PageHeader } from "@/components/layout";
import Button from "@/components/ui/Button";
import Icon from "@/components/ui/Icon";
import RequireAuth from "@/components/auth/RequireAuth";
import { useAuth } from "@/lib/auth/AuthContext";
import { resendVerification } from "@/lib/auth/client";
import { routes } from "@/lib/routes";

/**
 * Placeholder account landing page — P3 only establishes authentication
 * and route protection. Orders/entries/wins/etc. are separate future
 * phases (see docs/ROADMAP.md) and intentionally don't exist yet.
 */
function AccountContent() {
  const { user, logout } = useAuth();
  const router = useRouter();
  const [loggingOut, setLoggingOut] = useState(false);
  const [resending, setResending] = useState(false);
  const [resent, setResent] = useState(false);

  async function onLogout() {
    setLoggingOut(true);
    try {
      await logout();
      router.push(routes.home);
    } finally {
      setLoggingOut(false);
    }
  }

  async function onResend() {
    setResending(true);
    try {
      await resendVerification();
      setResent(true);
    } finally {
      setResending(false);
    }
  }

  if (!user) return null;

  return (
    <>
      <PageHeader eyebrow="Account" title={`Welcome back, ${user.name}`} />
      <Container className="pb-16">
        <div className="max-w-lg rounded-card border border-line bg-card p-6 card-shadow">
          {!user.email_verified_at && (
            <div className="mb-5 flex items-start gap-3 rounded-lg border border-amber-300 bg-amber-50 p-4">
              <Icon name="lock" className="mt-0.5 h-4 w-4 shrink-0 text-amber-700" />
              <div className="text-sm text-amber-900">
                <p className="font-semibold">Please verify your email</p>
                <p className="mt-1">
                  {resent
                    ? "A new verification link has been sent — check your inbox."
                    : "Check your inbox for a verification link, or resend it below."}
                </p>
                {!resent && (
                  <button
                    type="button"
                    onClick={onResend}
                    disabled={resending}
                    className="mt-2 text-sm font-semibold text-pink-700 hover:text-pink-600 disabled:opacity-50"
                  >
                    {resending ? "Sending…" : "Resend verification email"}
                  </button>
                )}
              </div>
            </div>
          )}

          <dl className="space-y-3 text-sm">
            <div className="flex justify-between gap-4">
              <dt className="text-muted">Name</dt>
              <dd className="font-medium text-heading">{user.name}</dd>
            </div>
            <div className="flex justify-between gap-4">
              <dt className="text-muted">Email</dt>
              <dd className="font-medium text-heading">{user.email}</dd>
            </div>
            <div className="flex justify-between gap-4">
              <dt className="text-muted">Email verified</dt>
              <dd className="font-medium text-heading">
                {user.email_verified_at ? "Yes" : "Not yet"}
              </dd>
            </div>
          </dl>

          <Button
            onClick={onLogout}
            loading={loggingOut}
            disabled={loggingOut}
            variant="secondary"
            block
            className="mt-6"
          >
            Sign out
          </Button>
        </div>
      </Container>
    </>
  );
}

export default function AccountPage() {
  return (
    <RequireAuth>
      <AccountContent />
    </RequireAuth>
  );
}
