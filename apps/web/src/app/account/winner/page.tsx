"use client";

import { useEffect, useState } from "react";
import Button from "@/components/ui/Button";
import Icon from "@/components/ui/Icon";
import EmptyState from "@/components/ui/EmptyState";
import { Textarea } from "@/components/ui/form";
import RequireAuth from "@/components/auth/RequireAuth";
import AccountPageHeading from "@/components/account/AccountPageHeading";
import { getCurrentWinner, submitWinnerClaim } from "@/lib/winners/client";
import type { ApiWinner, ApiWinnerStatus } from "@/lib/winners/types";
import { ApiValidationError } from "@/lib/api/http";
import { gbp, dateMedium } from "@/lib/format";
import { routes } from "@/lib/routes";

type State =
  | { kind: "loading" }
  | { kind: "error" }
  | { kind: "empty" }
  | { kind: "ready"; winner: ApiWinner };

const STEPS: { status: ApiWinnerStatus; label: string }[] = [
  { status: "pending_contact", label: "Winner identified" },
  { status: "contacted", label: "Contacted" },
  { status: "verification_pending", label: "Verifying" },
  { status: "verified", label: "Verified" },
  { status: "claim_approved", label: "Claim approved" },
  { status: "delivery_pending", label: "Preparing delivery" },
  { status: "delivered", label: "Delivered" },
  { status: "completed", label: "Complete" },
];

function stepIndex(status: ApiWinnerStatus): number {
  return STEPS.findIndex((s) => s.status === status);
}

function StatusStepper({ status }: { status: ApiWinnerStatus }) {
  const current = stepIndex(status);

  return (
    <ol className="mt-6 space-y-2">
      {STEPS.map((step, i) => {
        const done = i < current;
        const active = i === current;

        return (
          <li key={step.status} className="flex items-center gap-3 text-sm">
            <span
              className={`grid h-6 w-6 shrink-0 place-items-center rounded-full border text-[11px] font-bold ${
                done
                  ? "border-success-700 bg-success-700 text-white"
                  : active
                    ? "border-gold bg-gold text-ink"
                    : "border-line bg-mist text-muted"
              }`}
            >
              {done ? <Icon name="check" className="h-3.5 w-3.5" /> : i + 1}
            </span>
            <span className={active ? "font-semibold text-heading" : done ? "text-heading" : "text-muted"}>
              {step.label}
            </span>
          </li>
        );
      })}
    </ol>
  );
}

function ClaimSection({ winner, onSubmitted }: { winner: ApiWinner; onSubmitted: (winner: ApiWinner) => void }) {
  const [notes, setNotes] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (winner.claim) {
    return (
      <div className="mt-6 rounded-card border border-line bg-mist p-5 text-sm">
        <p className="font-semibold text-heading">Claim submitted</p>
        <p className="mt-1 text-muted">
          {winner.claim.status === "rejected"
            ? `Your claim could not be verified${winner.claim.rejection_reason ? `: ${winner.claim.rejection_reason}` : "."}`
            : "We're reviewing your claim — you'll see updates here as it progresses."}
        </p>
        {winner.claim.submitted_at && (
          <p className="mt-2 text-xs text-muted">Submitted {dateMedium(winner.claim.submitted_at)}</p>
        )}
      </div>
    );
  }

  if (winner.status !== "contacted") {
    return (
      <div className="mt-6 rounded-card border border-line bg-mist p-5 text-sm text-muted">
        We&apos;ll be in touch to verify your win before a claim can be submitted.
      </div>
    );
  }

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSubmitting(true);
    setError(null);
    try {
      await submitWinnerClaim(winner.id, notes);
      const refreshed = await getCurrentWinner();
      if (refreshed) onSubmitted(refreshed);
    } catch (err) {
      if (err instanceof ApiValidationError) {
        setError(err.fieldError("claim") ?? "Couldn't submit your claim.");
      } else {
        setError("Something went wrong — please try again.");
      }
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <form onSubmit={onSubmit} className="mt-6 rounded-card border border-line bg-card p-5">
      <p className="font-display text-sm font-extrabold tracking-tight text-heading">Submit your claim</p>
      <p className="mt-1 text-sm text-muted">
        Add anything you&apos;d like us to know (best way to reach you, delivery notes, etc.) — optional.
      </p>
      <Textarea
        value={notes}
        onChange={(e) => setNotes(e.target.value)}
        rows={4}
        maxLength={2000}
        className="mt-3"
        placeholder="Optional notes"
      />
      {error && <p className="mt-2 text-sm text-red-600">{error}</p>}
      <Button type="submit" loading={submitting} disabled={submitting} className="mt-4">
        Submit claim
      </Button>
    </form>
  );
}

function WinnerCard({ winner: initial }: { winner: ApiWinner }) {
  const [winner, setWinner] = useState(initial);

  return (
    <div className="max-w-2xl rounded-card border border-line bg-card p-6 card-shadow">
      <div className="flex items-start gap-4">
        <span className="grid h-14 w-14 shrink-0 place-items-center rounded-2xl border border-line bg-mist text-gold-700">
          <Icon name="trophy" className="h-6 w-6" />
        </span>
        <div className="min-w-0">
          <p className="font-display text-lg font-extrabold tracking-tight text-heading">
            {winner.competition.title}
          </p>
          <p className="mt-1 text-sm text-muted">
            Winning ticket #{winner.winning_ticket_number}
            {winner.prize && <> · {winner.prize.title} ({gbp(winner.prize.value / 100)})</>}
          </p>
        </div>
      </div>

      <StatusStepper status={winner.status} />

      {winner.delivery && (
        <div className="mt-6 rounded-card border border-line bg-mist p-5 text-sm">
          <p className="font-semibold text-heading">Delivery</p>
          <p className="mt-1 text-muted capitalize">{winner.delivery.status.replace("_", " ")}</p>
          {winner.delivery.tracking_reference && (
            <p className="mt-1 text-muted">Tracking: {winner.delivery.tracking_reference}</p>
          )}
          {winner.delivery.delivered_at && (
            <p className="mt-1 text-muted">Delivered {dateMedium(winner.delivery.delivered_at)}</p>
          )}
        </div>
      )}

      <ClaimSection winner={winner} onSubmitted={setWinner} />
    </div>
  );
}

function WinnerContent() {
  const [state, setState] = useState<State>({ kind: "loading" });

  useEffect(() => {
    let cancelled = false;
    async function load() {
      setState({ kind: "loading" });
      try {
        const winner = await getCurrentWinner();
        if (cancelled) return;
        setState(winner ? { kind: "ready", winner } : { kind: "empty" });
      } catch {
        if (!cancelled) setState({ kind: "error" });
      }
    }
    void load();
    return () => {
      cancelled = true;
    };
  }, []);

  if (state.kind === "loading") {
    return <div className="h-40 max-w-2xl animate-pulse rounded-card bg-mist" aria-hidden="true" />;
  }

  if (state.kind === "error") {
    return (
      <EmptyState
        icon="trophy"
        title="Couldn't load your winner status"
        description="Something went wrong reaching the server. Please try again."
        action={
          <Button variant="secondary" onClick={() => window.location.reload()}>
            Retry
          </Button>
        }
      />
    );
  }

  if (state.kind === "empty") {
    return (
      <EmptyState
        icon="trophy"
        title="No wins yet"
        description="Every draw is public and every ticket has a shot — keep an eye on your entries."
        action={<Button href={routes.competitions}>Browse competitions</Button>}
      />
    );
  }

  return <WinnerCard winner={state.winner} />;
}

export default function AccountWinnerPage() {
  return (
    <RequireAuth>
      <div className="space-y-8">
        <AccountPageHeading
          eyebrow="Account"
          title="My wins"
          lede="Prize status and claim details for any competition you win."
        />
        <WinnerContent />
      </div>
    </RequireAuth>
  );
}
