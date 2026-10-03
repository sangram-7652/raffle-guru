"use client";

import Link from "next/link";
import Button from "@/components/ui/Button";
import Icon from "@/components/ui/Icon";
import EmptyState from "@/components/ui/EmptyState";
import RequireAuth from "@/components/auth/RequireAuth";
import AccountPageHeading from "@/components/account/AccountPageHeading";
import AccountStatusCard from "@/components/account/AccountStatusCard";
import ActiveCompetitionCard from "@/components/account/ActiveCompetitionCard";
import SummaryCard from "@/components/account/SummaryCard";
import UpcomingDrawsList from "@/components/account/UpcomingDrawsList";
import VerificationBanner from "@/components/account/VerificationBanner";
import { useAuth } from "@/lib/auth/AuthContext";
import { orderStatusLabel } from "@/lib/account/summary";
import {
  useAccountOverview,
  type AccountOverview,
  type AccountOverviewState,
} from "@/lib/account/useAccountOverview";
import { useResendVerification } from "@/lib/account/useResendVerification";
import { dateMedium, gbp } from "@/lib/format";
import { routes } from "@/lib/routes";

const DASH = "—";

function Skeleton({ className = "" }: { className?: string }) {
  return <div className={`animate-pulse rounded-card bg-mist ${className}`} aria-hidden="true" />;
}

function SectionTitle({ children }: { children: React.ReactNode }) {
  return (
    <h2 className="font-display text-lg font-extrabold tracking-tight text-heading">{children}</h2>
  );
}

/** A summary figure: the real value once loaded, a dash if the load
 * failed (never a fabricated zero), a skeleton while it is in flight. */
function metric(state: AccountOverviewState, pick: (data: AccountOverview) => React.ReactNode) {
  if (state.kind === "ready") return pick(state.data);
  if (state.kind === "error") return DASH;
  return <Skeleton className="h-9 w-12" />;
}

function ActiveCompetitions({ state }: { state: AccountOverviewState }) {
  if (state.kind === "loading") {
    return (
      <div className="grid gap-4 sm:grid-cols-2">
        <Skeleton className="h-72" />
        <Skeleton className="h-72" />
      </div>
    );
  }

  if (state.kind === "error") {
    return (
      <EmptyState
        icon="ticket"
        title="Couldn't load your entries"
        description="Something went wrong reaching the server. Please try again."
        action={
          <Button variant="secondary" onClick={() => window.location.reload()}>
            Retry
          </Button>
        }
      />
    );
  }

  const { activeCompetitions } = state.data.summary;

  if (activeCompetitions.length === 0) {
    return (
      <EmptyState
        icon="ticket"
        title="No active entries yet"
        description="Enter a competition and your tickets will appear here."
        action={<Button href={routes.competitions}>Browse competitions</Button>}
      />
    );
  }

  return (
    <div className="grid gap-4 sm:grid-cols-2">
      {activeCompetitions.map((competition) => (
        <ActiveCompetitionCard key={competition.id} competition={competition} />
      ))}
    </div>
  );
}

function RecentActivity({ state }: { state: AccountOverviewState }) {
  if (state.kind === "loading") {
    return <Skeleton className="h-40" />;
  }

  const orders = state.kind === "ready" ? state.data.recentOrders : null;

  if (state.kind === "error" || orders === null) {
    return <p className="text-sm text-muted">Recent activity couldn&apos;t be loaded right now.</p>;
  }

  if (orders.length === 0) {
    return <p className="text-sm text-muted">Your orders and entry updates will appear here.</p>;
  }

  return (
    <ul className="divide-y divide-line">
      {orders.slice(0, 5).map((order) => (
        <li key={order.id} className="flex items-center justify-between gap-3 py-3 first:pt-0 last:pb-0">
          <div className="min-w-0">
            <p className="truncate text-sm font-semibold text-heading">Order {order.reference}</p>
            <p className="mt-0.5 text-xs text-muted">
              {orderStatusLabel(order.status)}
              {order.created_at && <> · {dateMedium(order.created_at)}</>}
            </p>
          </div>
          <span className="shrink-0 text-sm font-semibold tabular-nums text-heading">
            {gbp(order.total / 100)}
          </span>
        </li>
      ))}
    </ul>
  );
}

function OverviewContent() {
  const { user } = useAuth();
  const overview = useAccountOverview();
  const resend = useResendVerification();

  if (!user) return null;

  const firstName = user.name.split(" ")[0];
  const ready = overview.kind === "ready" ? overview.data : null;
  const isVerified = Boolean(user.email_verified_at);

  return (
    <div className="space-y-8">
      <AccountPageHeading
        eyebrow="Account overview"
        title={`Welcome back, ${firstName}`}
        lede="Track your entries, tickets and upcoming draws."
      />

      {!isVerified && (
        <VerificationBanner sending={resend.sending} sent={resend.sent} onResend={resend.resend} />
      )}

      <section aria-label="Summary" className="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
        <SummaryCard label="My entries" icon="ticket" value={metric(overview, (d) => d.summary.entryCount)} />
        <SummaryCard label="My tickets" icon="ticket" value={metric(overview, (d) => d.summary.ticketCount)} />
        <SummaryCard
          label="Upcoming draws"
          icon="clock"
          value={metric(overview, (d) => d.summary.upcomingDraws.length)}
        />
        <SummaryCard label="My wins" icon="trophy" value={metric(overview, (d) => d.winCount ?? DASH)} />
      </section>

      <div className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_19rem] lg:items-start">
        <div className="space-y-5">
          <div className="flex items-center justify-between gap-4">
            <SectionTitle>Active competitions</SectionTitle>
            {ready && ready.summary.entryCount > 0 && (
              <Link
                href={routes.accountEntries}
                className="text-sm font-semibold text-gold-700 transition-colors hover:text-heading focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold-700/60"
              >
                All entries
              </Link>
            )}
          </div>
          <ActiveCompetitions state={overview} />
        </div>

        <div className="space-y-6">
          <AccountStatusCard name={user.name} email={user.email} verified={isVerified} />

          <section className="rounded-card border border-line bg-card p-5 card-shadow sm:p-6">
            <div className="flex items-center gap-2">
              <Icon name="clock" className="h-4 w-4 text-gold-700" />
              <SectionTitle>Upcoming draws</SectionTitle>
            </div>
            <div className="mt-4">
              {overview.kind === "loading" ? (
                <Skeleton className="h-24" />
              ) : overview.kind === "error" ? (
                <p className="text-sm text-muted">Upcoming draws couldn&apos;t be loaded right now.</p>
              ) : (
                <UpcomingDrawsList draws={overview.data.summary.upcomingDraws} />
              )}
            </div>
          </section>

          <section className="rounded-card border border-line bg-card p-5 card-shadow sm:p-6">
            <SectionTitle>Recent activity</SectionTitle>
            <div className="mt-4">
              <RecentActivity state={overview} />
            </div>
          </section>
        </div>
      </div>
    </div>
  );
}

export default function AccountPage() {
  return (
    <RequireAuth>
      <OverviewContent />
    </RequireAuth>
  );
}
