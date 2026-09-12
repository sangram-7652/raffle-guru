"use client";

import Link from "next/link";
import { useCart, type CartItem } from "./CartContext";
import QuantityStepper from "../ui/QuantityStepper";
import Icon from "../ui/Icon";
import ImageFrame from "../media/ImageFrame";
import { gbp } from "@/lib/format";
import { routes } from "@/lib/routes";

export default function CartLineItem({ item }: { item: CartItem }) {
  const { setQuantity, removeItem } = useCart();

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
                className="hover:text-pink-700 focus-visible:outline-none focus-visible:underline"
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
            onClick={() => removeItem(item.slug)}
            aria-label={`Remove ${item.title}`}
            className="grid h-8 w-8 shrink-0 place-items-center rounded-full text-muted transition-colors hover:bg-panel hover:text-error-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pink/50"
          >
            <Icon name="close" className="h-4 w-4" />
          </button>
        </div>

        <div className="mt-auto flex items-end justify-between gap-3 pt-3">
          <QuantityStepper
            value={item.quantity}
            onChange={(q) => setQuantity(item.slug, q)}
            min={1}
            max={999}
            label={`entries for ${item.title}`}
          />
          <span className="font-display text-base font-extrabold tabular-nums text-heading">
            {gbp(item.entryPrice * item.quantity)}
          </span>
        </div>
      </div>
    </li>
  );
}
