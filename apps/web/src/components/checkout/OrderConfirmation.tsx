"use client";

import { useEffect, useState } from "react";
import { useSearchParams } from "next/navigation";
import Button from "../ui/Button";
import Icon from "../ui/Icon";
import { gbp, dateMedium } from "@/lib/format";
import { getOrder } from "@/lib/orders/client";
import type { ApiOrder } from "@/lib/orders/types";
import { routes } from "@/lib/routes";

const nextSteps = [
  {
    icon: "check" as const,
    title: "Order created",
    body: "We've created your order and it's waiting on payment — no payment has been taken yet.",
  },
  {
    icon: "clock" as const,
    title: "Payment",
    body: "Payment isn't connected yet in this phase. We'll follow up once checkout can take payment.",
  },
  {
    icon: "trophy" as const,
    title: "The draw",
    body: "Once an order is paid, entries are allocated and the competition draws on its published date.",
  },
];

type State =
  | { kind: "loading" }
  | { kind: "not-found" }
  | { kind: "ready"; order: ApiOrder };

export default function OrderConfirmation() {
  const searchParams = useSearchParams();
  const reference = searchParams.get("reference");
  const [state, setState] = useState<State>({ kind: "loading" });

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

  return (
    <div className="mx-auto max-w-3xl">
      <div className="flex flex-col items-center text-center">
        <span className="grid h-16 w-16 place-items-center rounded-full bg-success text-white">
          <Icon name="check" className="h-8 w-8" />
        </span>
        <h1 className="mt-5 t-h1 text-heading">Order created — payment pending</h1>
        <p className="mt-3 t-body-lg text-muted">
          Your order for {entryCount} {entryCount === 1 ? "entry" : "entries"}{" "}
          has been created. No payment has been taken yet.
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
          {order.items.map((i) => (
            <li key={i.id} className="flex items-center justify-between gap-4 py-4">
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
            </li>
          ))}
        </ul>

        <dl className="space-y-2 border-t border-line px-5 py-4 text-sm sm:px-6">
          <div className="flex justify-between">
            <dt className="text-muted">Subtotal</dt>
            <dd className="tabular-nums text-heading">{gbp(order.subtotal / 100)}</dd>
          </div>
          <div className="flex justify-between border-t border-line pt-2 font-display text-base font-extrabold text-heading">
            <dt>Total (payment pending)</dt>
            <dd className="tabular-nums">{gbp(order.total / 100)}</dd>
          </div>
        </dl>
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
            <span className="grid h-9 w-9 place-items-center rounded-xl border border-line bg-panel text-pink-600">
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
