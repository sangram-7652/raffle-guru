"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { Container, PageHeader } from "@/components/layout";
import Button from "@/components/ui/Button";
import Icon from "@/components/ui/Icon";
import RequireAuth from "@/components/auth/RequireAuth";
import { useAuth } from "@/lib/auth/AuthContext";
import { resendVerification } from "@/lib/auth/client";
import { listOrders } from "@/lib/orders/client";
import type { ApiOrder } from "@/lib/orders/types";
import { gbp } from "@/lib/format";
import { routes } from "@/lib/routes";

const STATUS_LABEL: Record<ApiOrder["status"], string> = {
  pending: "Payment pending",
  payment_pending: "Payment pending",
  paid: "Paid",
  processing: "Processing",
  completed: "Completed",
  cancelled: "Cancelled",
  failed: "Failed",
  refunded: "Refunded",
};

/**
 * Account landing page (P3) plus a compact recent-orders section (P5) —
 * full order history/filtering is out of scope here, this only surfaces
 * the customer's most recent orders. Entries (P7) and winner/claim
 * status (P10) live on their own dedicated pages, linked below.
 */
function RecentOrders() {
  const [orders, setOrders] = useState<ApiOrder[] | null>(null);

  useEffect(() => {
    listOrders(1)
      .then((res) => setOrders(res.data))
      .catch(() => setOrders([]));
  }, []);

  if (orders === null) {
    return <div className="mt-6 h-24 rounded-card border border-line bg-mist" />;
  }

  if (orders.length === 0) {
    return null;
  }

  return (
    <div className="mt-6 max-w-lg rounded-card border border-line bg-card p-6 card-shadow">
      <h2 className="font-display text-sm font-extrabold tracking-tight text-heading">
        Recent orders
      </h2>
      <ul className="mt-4 divide-y divide-line">
        {orders.slice(0, 5).map((order) => (
          <li key={order.id} className="flex items-center justify-between gap-3 py-3 text-sm">
            <div className="min-w-0">
              <p className="font-medium text-heading">{order.reference}</p>
              <p className="text-xs text-muted">{STATUS_LABEL[order.status]}</p>
            </div>
            <span className="shrink-0 tabular-nums text-heading">
              {gbp(order.total / 100)}
            </span>
          </li>
        ))}
      </ul>
    </div>
  );
}

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
                    className="mt-2 text-sm font-semibold text-gold-700 hover:text-gold-700 disabled:opacity-50"
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

        <div className="mt-6 flex max-w-lg flex-wrap gap-3">
          <Button href={routes.accountEntries} variant="secondary">
            <Icon name="ticket" className="h-4 w-4" />
            My entries
          </Button>
          <Button href={routes.accountWinner} variant="secondary">
            <Icon name="trophy" className="h-4 w-4" />
            My win
          </Button>
        </div>

        <RecentOrders />
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
