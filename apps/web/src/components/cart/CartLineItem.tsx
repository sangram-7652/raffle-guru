"use client";

import { useState } from "react";
import Link from "next/link";
import { useCart, type CartItem } from "./CartContext";
import QuantityStepper from "../ui/QuantityStepper";
import Icon from "../ui/Icon";
import ImageFrame from "../media/ImageFrame";
import { gbp } from "@/lib/format";
import { routes } from "@/lib/routes";

export default function CartLineItem({ item }: { item: CartItem }) {
  const { setQuantity, removeItem } = useCart();
  const [error, setError] = useState<string | null>(null);

  async function onQuantityChange(q: number) {
    setError(null);
    try {
      await setQuantity(item.id, q);
    } catch {
      setError("Couldn't update quantity — please try again.");
    }
  }

  async function onRemove() {
    setError(null);
    try {
      await removeItem(item.id);
    } catch {
      setError("Couldn't remove this item — please try again.");
    }
  }

  return (
    <li className="flex gap-4 py-5">
      <Link
        href={routes.competition(item.slug)}
        className="w-24 shrink-0 sm:w-32"
        tabIndex={-1}
        aria-hidden
      >
        <ImageFrame
          src={item.image}
          alt={item.title}
          ratio="4/3"
          radius="card"
          sizes="128px"
        />
      </Link>

      <div className="flex min-w-0 flex-1 flex-col">
        <div className="flex items-start justify-between gap-3">
          <div className="min-w-0">
            <h3 className="font-display text-sm font-extrabold leading-snug tracking-tight text-heading">
              <Link
                href={routes.competition(item.slug)}
                className="hover:text-gold-700 focus-visible:outline-none focus-visible:underline"
              >
                {item.title}
              </Link>
            </h3>
            <p className="mt-0.5 text-xs text-muted">
              Draws {item.drawLabel} · {gbp(item.entryPrice)} per entry
            </p>
          </div>
          <button
            type="button"
            onClick={onRemove}
            aria-label={`Remove ${item.title}`}
            className="grid h-8 w-8 shrink-0 place-items-center rounded-full text-muted transition-colors hover:bg-panel hover:text-error-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold-700/50"
          >
            <Icon name="close" className="h-4 w-4" />
          </button>
        </div>

        <div className="mt-auto flex items-end justify-between gap-3 pt-3">
          <QuantityStepper
            value={item.quantity}
            onChange={onQuantityChange}
            min={1}
            max={100}
            label={`entries for ${item.title}`}
          />
          <span className="font-display text-base font-extrabold tabular-nums text-heading">
            {gbp(item.entryPrice * item.quantity)}
          </span>
        </div>

        {error && (
          <p className="mt-2 flex items-center gap-1.5 text-xs font-medium text-error-600">
            <Icon name="close" className="h-3.5 w-3.5 shrink-0" />
            {error}
          </p>
        )}
      </div>
    </li>
  );
}
