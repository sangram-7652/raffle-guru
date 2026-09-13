"use client";

import type { ReactNode } from "react";
import { useCart } from "./CartContext";
import { gbp } from "@/lib/format";

/** Subtotal / total block. Shared by the cart and checkout. No discount
 * row — no promotion/coupon logic exists (see docs/SCHEMA_DESIGN_P5.md);
 * subtotal and total are always equal in P5. */
export default function OrderSummary({
  children,
  showItems = false,
}: {
  children?: ReactNode;
  showItems?: boolean;
}) {
  const { items, subtotal, total, count } = useCart();

  return (
    <div className="rounded-card border border-line bg-card p-5 card-shadow sm:p-6">
      <h2 className="font-display text-lg font-extrabold tracking-tight text-heading">
        Order summary
      </h2>

      {showItems && items.length > 0 && (
        <ul className="mt-4 space-y-2 border-b border-line pb-4 text-sm">
          {items.map((i) => (
            <li key={i.id} className="flex justify-between gap-3 text-muted">
              <span className="min-w-0 truncate">
                {i.title}{" "}
                <span className="text-xs">× {i.quantity}</span>
              </span>
              <span className="shrink-0 tabular-nums text-heading">
                {gbp(i.entryPrice * i.quantity)}
              </span>
            </li>
          ))}
        </ul>
      )}

      <dl className="mt-4 space-y-2.5 text-sm">
        <div className="flex justify-between">
          <dt className="text-muted">
            Subtotal
            <span className="ml-1 text-xs">
              ({count} {count === 1 ? "entry" : "entries"})
            </span>
          </dt>
          <dd className="tabular-nums text-heading">{gbp(subtotal)}</dd>
        </div>
        <div className="flex justify-between border-t border-line pt-3 font-display text-base font-extrabold text-heading">
          <dt>Total</dt>
          <dd className="tabular-nums">{gbp(total)}</dd>
        </div>
      </dl>

      {children && <div className="mt-5">{children}</div>}
    </div>
  );
}
