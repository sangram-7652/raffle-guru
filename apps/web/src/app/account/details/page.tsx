"use client";

import Link from "next/link";
import RequireAuth from "@/components/auth/RequireAuth";
import AccountPageHeading from "@/components/account/AccountPageHeading";
import VerificationBanner from "@/components/account/VerificationBanner";
import Icon from "@/components/ui/Icon";
import { useAuth } from "@/lib/auth/AuthContext";
import { useResendVerification } from "@/lib/account/useResendVerification";
import { routes } from "@/lib/routes";

function AccountDetailsContent() {
  const { user } = useAuth();
  const resend = useResendVerification();

  if (!user) return null;

  const isVerified = Boolean(user.email_verified_at);

  return (
    <div className="space-y-8">
      <AccountPageHeading
        eyebrow="Account"
        title="Account details"
        lede="The details we hold for your RaffleGuru account."
      />

      {!isVerified && (
        <VerificationBanner sending={resend.sending} sent={resend.sent} onResend={resend.resend} />
      )}

      <section className="max-w-2xl overflow-hidden rounded-card border border-line bg-card card-shadow">
        <dl className="divide-y divide-line">
          <div className="grid gap-1 p-5 sm:grid-cols-[10rem_minmax(0,1fr)] sm:items-center sm:gap-4 sm:p-6">
            <dt className="t-label text-muted">Name</dt>
            <dd className="font-medium text-heading">{user.name}</dd>
          </div>
          <div className="grid gap-1 p-5 sm:grid-cols-[10rem_minmax(0,1fr)] sm:items-center sm:gap-4 sm:p-6">
            <dt className="t-label text-muted">Email</dt>
            <dd className="break-all font-medium text-heading">{user.email}</dd>
          </div>
          <div className="grid gap-1 p-5 sm:grid-cols-[10rem_minmax(0,1fr)] sm:items-center sm:gap-4 sm:p-6">
            <dt className="t-label text-muted">Email verification</dt>
            <dd>
              {isVerified ? (
                <span className="inline-flex items-center gap-1.5 font-medium text-success-600">
                  <Icon name="check" className="h-4 w-4" />
                  Verified
                </span>
              ) : (
                <span className="inline-flex items-center gap-1.5 font-medium text-warning">
                  <Icon name="lock" className="h-4 w-4" />
                  Not verified
                </span>
              )}
            </dd>
          </div>
        </dl>
      </section>

      <p className="max-w-2xl text-sm text-muted">
        Need to change these details?{" "}
        <Link
          href={routes.contact}
          className="font-semibold text-gold-700 underline-offset-4 hover:text-heading hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold-700/60"
        >
          Get in touch
        </Link>{" "}
        and we&apos;ll help.
      </p>
    </div>
  );
}

export default function AccountDetailsPage() {
  return (
    <RequireAuth>
      <AccountDetailsContent />
    </RequireAuth>
  );
}
