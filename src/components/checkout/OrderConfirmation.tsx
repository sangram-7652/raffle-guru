"use client";

import { useMemo } from "react";
import Button from "../ui/Button";
import Icon from "../ui/Icon";
import { gbp, dateMedium } from "@/lib/format";
import { readMockOrder, type MockOrder } from "@/lib/order";
import { useHydrated } from "@/lib/hydrated";
import { routes } from "@/lib/routes";

const SAMPLE: MockOrder = {
  reference: "RG-482915",
  placedAt: "2026-08-30T12:00:00Z",
  email: "you@example.com",
  name: "Sample Entrant",
  items: [
    {
      slug: "bmw-m4",
      title: "BMW M4 Competition",
      quantity: 10,
      lineTotal: 9.9,
      firstTicket: 12480,
    },
    {
      slug: "cash-25k",
      title: "£25,000 Tax-Free Cash",
      quantity: 5,
      lineTotal: 3.95,
      firstTicket: 30211,
    },
  ],
  entryCount: 15,
  subtotal: 13.85,
  discount: 0,
  promoCode: null,
  total: 13.85,
};

const nextSteps = [
  {
    icon: "check" as const,
    title: "Confirmation email",
    body: "We'd email your full list of ticket numbers to the address on your order.",
  },
  {
    icon: "clock" as const,
    title: "Watch the live draw",
    body: "Each competition draws on its published date, streamed live with a verified random draw.",
  },
  {
    icon: "trophy" as const,
    title: "If you win",
    body: "We call and email you within 24 hours. Cash lands within 48 hours; prizes are arranged directly.",
  },
];

export default function OrderConfirmation() {
  const hydrated = useHydrated();
  const { order, isSample } = useMemo(() => {
    if (!hydrated) return { order: null as MockOrder | null, isSample: false };
    const found = readMockOrder();
    return { order: found ?? SAMPLE, isSample: !found };
  }, [hydrated]);

  if (!order) {
    return <div className="h-96 rounded-card border border-line bg-mist" />;
  }

  return (
    <div className="mx-auto max-w-3xl">
      <div className="flex flex-col items-center text-center">
        <span className="grid h-16 w-16 place-items-center rounded-full bg-success text-white">
          <Icon name="check" className="h-8 w-8" />
        </span>
        <h1 className="mt-5 t-h1 text-heading">You&apos;re entered</h1>
        <p className="mt-3 t-body-lg text-muted">
          Thanks {order.name.split(" ")[0]} — your {order.entryCount}{" "}
          {order.entryCount === 1 ? "entry is" : "entries are"} confirmed. Good
          luck in the draw.
        </p>
        {isSample && (
          <p className="mt-3 rounded-full border border-warning/30 bg-warning/10 px-3 py-1 text-xs font-medium text-warning">
            Sample confirmation — no order was placed
          </p>
        )}
      </div>

      <div className="mt-8 rounded-card border border-line bg-card card-shadow">
        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-4 sm:px-6">
          <div>
            <p className="t-eyebrow text-muted">Order reference</p>
            <p className="font-display text-lg font-extrabold tracking-tight text-heading">
              {order.reference}
            </p>
          </div>
          <p className="text-xs text-muted">
            Placed {dateMedium(order.placedAt)} · {order.email}
          </p>
        </div>

        <ul className="divide-y divide-line px-5 sm:px-6">
          {order.items.map((i) => (
            <li key={i.slug} className="flex items-center justify-between gap-4 py-4">
              <div className="min-w-0">
                <p className="truncate font-display text-sm font-extrabold tracking-tight text-heading">
                  {i.title}
                </p>
                <p className="mt-0.5 text-xs text-muted">
                  {i.quantity} {i.quantity === 1 ? "entry" : "entries"} · tickets{" "}
                  #{i.firstTicket}–#{i.firstTicket + i.quantity - 1}
                </p>
              </div>
              <span className="shrink-0 font-display text-sm font-extrabold tabular-nums text-heading">
                {gbp(i.lineTotal)}
              </span>
            </li>
          ))}
        </ul>

        <dl className="space-y-2 border-t border-line px-5 py-4 text-sm sm:px-6">
          <div className="flex justify-between">
            <dt className="text-muted">Subtotal</dt>
            <dd className="tabular-nums text-heading">{gbp(order.subtotal)}</dd>
          </div>
          {order.discount > 0 && (
            <div className="flex justify-between text-success-600">
              <dt>Discount{order.promoCode ? ` (${order.promoCode})` : ""}</dt>
              <dd className="tabular-nums">−{gbp(order.discount)}</dd>
            </div>
          )}
          <div className="flex justify-between border-t border-line pt-2 font-display text-base font-extrabold text-heading">
            <dt>Total paid</dt>
            <dd className="tabular-nums">{gbp(order.total)}</dd>
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
          href={routes.login}
          variant="secondary"
          size="lg"
          className="sm:flex-1"
        >
          View my entries
        </Button>
      </div>
    </div>
  );
}
