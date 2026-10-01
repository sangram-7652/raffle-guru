"use client";

import Link from "next/link";
import { useCart } from "./CartContext";
import CartLineItem from "./CartLineItem";
import OrderSummary from "./OrderSummary";
import Button from "../ui/Button";
import Icon from "../ui/Icon";
import EmptyState from "../ui/EmptyState";
import { routes } from "@/lib/routes";

export default function CartView() {
  const { items, count, clear, status, refresh } = useCart();

  if (status === "loading") {
    return (
      <div className="grid gap-8 lg:grid-cols-[1fr_22rem]">
        <div className="h-64 rounded-card border border-line bg-mist" />
        <div className="h-64 rounded-card border border-line bg-mist" />
      </div>
    );
  }

  if (status === "guest") {
    return (
      <EmptyState
        icon="lock"
        title="Sign in to see your basket"
        description="Your basket is tied to your account — sign in to view or add entries."
        action={
          <Button href={`${routes.login}?redirect=${encodeURIComponent(routes.cart)}`}>
            Sign in
          </Button>
        }
      />
    );
  }

  if (status === "error") {
    return (
      <EmptyState
        icon="ticket"
        title="Couldn't load your basket"
        description="Something went wrong reaching the server. Please try again."
        action={
          <Button variant="secondary" onClick={() => void refresh()}>
            Retry
          </Button>
        }
      />
    );
  }

  if (items.length === 0) {
    return (
      <EmptyState
        icon="ticket"
        title="Your basket is empty"
        description="Add some entries from a competition and they'll show up here."
        action={
          <Button href={routes.competitions}>
            Browse competitions
            <Icon name="arrow" className="h-4 w-4" />
          </Button>
        }
      />
    );
  }

  return (
    <div className="grid gap-8 lg:grid-cols-[1fr_22rem] lg:gap-12">
      <div>
        <div className="flex items-center justify-between">
          <h2 className="font-display text-lg font-extrabold tracking-tight text-heading">
            {count} {count === 1 ? "entry" : "entries"} in{" "}
            {items.length} competition{items.length === 1 ? "" : "s"}
          </h2>
          <button
            type="button"
            onClick={() => void clear()}
            className="text-xs font-semibold text-muted underline underline-offset-2 hover:text-heading"
          >
            Clear basket
          </button>
        </div>

        <ul className="mt-2 divide-y divide-line border-y border-line">
          {items.map((item) => (
            <CartLineItem key={item.id} item={item} />
          ))}
        </ul>

        <Link
          href={routes.competitions}
          className="mt-5 inline-flex items-center gap-1.5 text-sm font-semibold text-gold-700 hover:text-gold"
        >
          <Icon name="arrow" className="h-4 w-4 rotate-180" />
          Continue browsing
        </Link>
      </div>

      <div className="lg:sticky lg:top-24 lg:self-start">
        <OrderSummary>
          <div className="space-y-4">
            <Button href={routes.checkout} block size="lg">
              Checkout
              <Icon name="arrow" className="h-4 w-4" />
            </Button>
            <p className="flex items-center justify-center gap-1.5 text-xs text-muted">
              <Icon name="lock" className="h-3.5 w-3.5" />
              No payment is taken yet — payment isn&apos;t connected until a
              later phase.
            </p>
          </div>
        </OrderSummary>
      </div>
    </div>
  );
}
