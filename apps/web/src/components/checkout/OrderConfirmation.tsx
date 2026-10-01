"use client";

import { useEffect, useRef, useState } from "react";
import { useSearchParams } from "next/navigation";
import Button from "../ui/Button";
import Icon from "../ui/Icon";
import { gbp, dateMedium } from "@/lib/format";
import { getOrder } from "@/lib/orders/client";
import type { ApiOrder } from "@/lib/orders/types";
import { useRazorpayCheckout } from "@/lib/payments/useRazorpayCheckout";
import { routes } from "@/lib/routes";

const paidNextSteps = [
  {
    icon: "check" as const,
    title: "Payment confirmed",
    body: "We've received your payment and it's been verified.",
  },
  {
    icon: "clock" as const,
    title: "Entries allocated",
    body: "Once allocation finishes, your ticket numbers appear on this page automatically.",
  },
  {
    icon: "trophy" as const,
    title: "The draw",
    body: "The competition draws on its published date, streamed live with a verified random draw.",
  },
];

const pendingNextSteps = [
  {
    icon: "check" as const,
    title: "Order created",
    body: "We've created your order and it's waiting on payment — no payment has been taken yet.",
  },
  {
    icon: "clock" as const,
    title: "Complete payment",
    body: "Finish payment above to confirm your entries — you can come back to this page any time.",
  },
  {
    icon: "trophy" as const,
    title: "The draw",
    body: "Once your order is paid, entries are allocated and the competition draws on its published date.",
  },
];

type State =
  | { kind: "loading" }
  | { kind: "not-found" }
  | { kind: "ready"; order: ApiOrder };

/** Server-confirmed states only — never inferred from the URL. */
function paymentHeading(order: ApiOrder): { title: string; body: string } {
  if (order.status === "paid" || order.status === "processing" || order.status === "completed") {
    return {
      title: "Payment successful",
      body: "Your payment has been verified and your order is confirmed.",
    };
  }

  if (order.payment?.status === "failed") {
    return {
      title: "Payment failed — retry payment",
      body: "Your last payment attempt didn't go through. No payment has been taken — you can try again below.",
    };
  }

  return {
    title: "Payment pending",
    body: "Your order has been created. No payment has been taken yet.",
  };
}

// How long to keep polling for allocation to complete before showing a
// "check back later" message instead of spinning forever. Allocation
// runs on a background queue (see docs/TICKET_ENGINE_DESIGN_P7.md) —
// normally near-instant, but never guaranteed to finish within any
// fixed window, so this is a UX cutoff, not a correctness assumption.
const ALLOCATION_POLL_INTERVAL_MS = 3000;
const ALLOCATION_POLL_MAX_ATTEMPTS = 10;

export default function OrderConfirmation() {
  const searchParams = useSearchParams();
  const reference = searchParams.get("reference");
  const [state, setState] = useState<State>({ kind: "loading" });
  const [pollGaveUp, setPollGaveUp] = useState(false);
  const pollAttempts = useRef(0);

  const { state: paymentState, pay } = useRazorpayCheckout((order) => {
    setState({ kind: "ready", order });
  });

  useEffect(() => {
    let cancelled = false;
    async function load() {
      if (!reference) {
        setState({ kind: "not-found" });
        return;
      }
      setState({ kind: "loading" });
      const order = await getOrder(reference);
      if (cancelled) return;
      setState(order ? { kind: "ready", order } : { kind: "not-found" });
    }
    void load();
    return () => {
      cancelled = true;
    };
  }, [reference]);

  // Order is paid but ticket allocation (an async background job —
  // see docs/TICKET_ENGINE_DESIGN_P7.md) hasn't produced entries yet:
  // poll until it does, or give up after a bounded number of attempts
  // rather than displaying a truthful "in progress" state forever.
  useEffect(() => {
    if (state.kind !== "ready") return;
    const { order } = state;
    const isPaid = order.status === "paid" || order.status === "processing" || order.status === "completed";
    if (!isPaid || order.entries.length > 0 || !reference) return;

    if (pollAttempts.current >= ALLOCATION_POLL_MAX_ATTEMPTS) {
      setPollGaveUp(true);
      return;
    }

    const timer = window.setTimeout(async () => {
      pollAttempts.current += 1;
      const refreshed = await getOrder(reference);
      if (refreshed) setState({ kind: "ready", order: refreshed });
    }, ALLOCATION_POLL_INTERVAL_MS);

    return () => window.clearTimeout(timer);
  }, [state, reference]);

  if (state.kind === "loading") {
    return <div className="h-96 rounded-card border border-line bg-mist" />;
  }

  if (state.kind === "not-found") {
    return (
      <div className="mx-auto max-w-lg text-center">
        <h1 className="t-h1 text-heading">Order not found</h1>
        <p className="mt-3 t-body-lg text-muted">
          We couldn&apos;t find that order on your account.
        </p>
        <div className="mt-8 flex flex-col gap-3 sm:flex-row sm:justify-center">
          <Button href={routes.competitions} size="lg">
            Continue browsing
          </Button>
          <Button href={routes.account} variant="secondary" size="lg">
            Go to my account
          </Button>
        </div>
      </div>
    );
  }

  const { order } = state;
  const entryCount = order.items.reduce((n, i) => n + i.quantity, 0);
  const isPaid = order.status === "paid" || order.status === "processing" || order.status === "completed";
  const canRetry = !isPaid && order.status !== "cancelled" && order.status !== "refunded" && order.status !== "failed";
  const heading = paymentHeading(order);
  const nextSteps = isPaid ? paidNextSteps : pendingNextSteps;
  const paying = paymentState.status === "processing" || paymentState.status === "verifying";
  const allocationPending = isPaid && order.entries.length === 0;

  return (
    <div className="mx-auto max-w-3xl">
      <div className="flex flex-col items-center text-center">
        <span
          className={`grid h-16 w-16 place-items-center rounded-full text-white ${isPaid ? "bg-success-700" : "bg-mist text-gold-700"}`}
        >
          <Icon name={isPaid ? "check" : "clock"} className="h-8 w-8" />
        </span>
        <h1 className="mt-5 t-h1 text-heading">{heading.title}</h1>
        <p className="mt-3 t-body-lg text-muted">
          {heading.body} Your order is for {entryCount}{" "}
          {entryCount === 1 ? "entry" : "entries"}.
        </p>
      </div>

      <div className="mt-8 rounded-card border border-line bg-card card-shadow">
        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-4 sm:px-6">
          <div>
            <p className="t-eyebrow text-muted">Order reference</p>
            <p className="font-display text-lg font-extrabold tracking-tight text-heading">
              {order.reference}
            </p>
          </div>
          {order.created_at && (
            <p className="text-xs text-muted">Placed {dateMedium(order.created_at)}</p>
          )}
        </div>

        <ul className="divide-y divide-line px-5 sm:px-6">
          {order.items.map((i) => {
            const entry = order.entries.find((e) => e.competition.id === i.competition_id);
            return (
              <li key={i.id} className="py-4">
                <div className="flex items-center justify-between gap-4">
                  <div className="min-w-0">
                    <p className="truncate font-display text-sm font-extrabold tracking-tight text-heading">
                      {i.competition_title}
                    </p>
                    <p className="mt-0.5 text-xs text-muted">
                      {i.quantity} {i.quantity === 1 ? "entry" : "entries"}
                    </p>
                  </div>
                  <span className="shrink-0 font-display text-sm font-extrabold tabular-nums text-heading">
                    {gbp(i.line_total / 100)}
                  </span>
                </div>
                {isPaid && (
                  <div className="mt-2">
                    {entry ? (
                      <p className="text-xs text-muted">
                        Ticket{entry.ticket_numbers.length === 1 ? "" : "s"}:{" "}
                        <span className="font-medium text-heading">
                          {entry.ticket_numbers.map((n) => `#${n}`).join(", ")}
                        </span>
                      </p>
                    ) : (
                      <p className="flex items-center gap-1.5 text-xs text-muted">
                        <Icon name="clock" className="h-3.5 w-3.5 shrink-0" />
                        Ticket allocation in progress
                        {pollGaveUp && " — check back shortly"}
                      </p>
                    )}
                  </div>
                )}
              </li>
            );
          })}
        </ul>

        <dl className="space-y-2 border-t border-line px-5 py-4 text-sm sm:px-6">
          <div className="flex justify-between">
            <dt className="text-muted">Subtotal</dt>
            <dd className="tabular-nums text-heading">{gbp(order.subtotal / 100)}</dd>
          </div>
          <div className="flex justify-between border-t border-line pt-2 font-display text-base font-extrabold text-heading">
            <dt>{isPaid ? "Total paid" : "Total (payment pending)"}</dt>
            <dd className="tabular-nums">{gbp(order.total / 100)}</dd>
          </div>
        </dl>

        {allocationPending && !pollGaveUp && (
          <div className="border-t border-line px-5 py-3 sm:px-6">
            <p className="flex items-center gap-1.5 text-xs text-muted">
              <Icon name="clock" className="h-3.5 w-3.5 shrink-0 animate-pulse" />
              Allocating your tickets — this page updates automatically.
            </p>
          </div>
        )}

        {canRetry && (
          <div className="border-t border-line px-5 py-4 sm:px-6">
            {paymentState.status === "dismissed" && (
              <p className="mb-3 flex items-center gap-1.5 text-xs font-medium text-warning">
                <Icon name="close" className="h-3.5 w-3.5 shrink-0" />
                Checkout closed — no payment was taken.
              </p>
            )}
            {paymentState.status === "error" && (
              <p role="alert" className="mb-3 flex items-center gap-1.5 text-xs font-medium text-error-600">
                <Icon name="close" className="h-3.5 w-3.5 shrink-0" />
                {paymentState.message}
              </p>
            )}
            <Button
              block
              size="lg"
              loading={paying}
              disabled={paying}
              onClick={() => void pay(order.reference)}
            >
              {paying ? "Processing payment" : "Complete payment"}
            </Button>
          </div>
        )}
      </div>

      <h2 className="mt-10 font-display text-lg font-extrabold tracking-tight text-heading">
        What happens next
      </h2>
      <ol className="mt-4 grid gap-4 sm:grid-cols-3">
        {nextSteps.map((s) => (
          <li
            key={s.title}
            className="rounded-2xl border border-line bg-card p-4"
          >
            <span className="grid h-9 w-9 place-items-center rounded-xl border border-line bg-panel text-gold-700">
              <Icon name={s.icon} className="h-4 w-4" />
            </span>
            <p className="mt-3 font-display text-sm font-bold tracking-tight text-heading">
              {s.title}
            </p>
            <p className="mt-1 text-xs leading-relaxed text-muted">{s.body}</p>
          </li>
        ))}
      </ol>

      <div className="mt-8 flex flex-col gap-3 sm:flex-row">
        <Button href={routes.competitions} size="lg" className="sm:flex-1">
          Continue browsing
        </Button>
        <Button
          href={routes.account}
          variant="secondary"
          size="lg"
          className="sm:flex-1"
        >
          View my orders
        </Button>
      </div>
    </div>
  );
}
