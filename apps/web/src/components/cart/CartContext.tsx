"use client";

import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useState,
  type ReactNode,
} from "react";
import { useAuth } from "@/lib/auth/AuthContext";
import * as cartClient from "@/lib/cart/client";
import { drawLabelShort } from "@/lib/format";
import type { ApiCartItem } from "@/lib/cart/types";

/**
 * Server-backed cart (P5) — replaces the P1 localStorage mock. A cart
 * requires authentication (no guest cart architecture — see
 * docs/SCHEMA_DESIGN_P5.md), so this reads auth state from AuthContext
 * (P3) and only talks to the API once a real session exists.
 */

export type CartItem = {
  id: number;
  competitionId: number;
  slug: string;
  title: string;
  image: string;
  entryPrice: number;
  quantity: number;
  drawLabel: string;
};

type CartStatus = "loading" | "ready" | "guest" | "error";

type CartValue = {
  items: CartItem[];
  count: number;
  subtotal: number;
  total: number;
  status: CartStatus;
  /** true once status has settled (ready/guest/error) — kept for the
   * same SSR-safe gating pattern components already used. */
  hydrated: boolean;
  addItem: (competitionId: number, quantity?: number) => Promise<void>;
  setQuantity: (itemId: number, quantity: number) => Promise<void>;
  removeItem: (itemId: number) => Promise<void>;
  clear: () => Promise<void>;
  refresh: () => Promise<void>;
};

const CartContext = createContext<CartValue | null>(null);

function adaptItem(api: ApiCartItem): CartItem {
  return {
    id: api.id,
    competitionId: api.competition.id,
    slug: api.competition.slug,
    title: api.competition.title,
    image: api.competition.image ?? "",
    entryPrice: api.unit_price / 100,
    quantity: api.quantity,
    drawLabel: api.competition.draw_at ? drawLabelShort(api.competition.draw_at) : "TBC",
  };
}

export function CartProvider({ children }: { children: ReactNode }) {
  const { status: authStatus } = useAuth();
  const [items, setItems] = useState<CartItem[]>([]);
  const [subtotal, setSubtotal] = useState(0);
  const [status, setStatus] = useState<CartStatus>("loading");

  const refresh = useCallback(async () => {
    try {
      const { data } = await cartClient.getCart();
      setItems(data.items.map(adaptItem));
      setSubtotal(data.subtotal / 100);
      setStatus("ready");
    } catch {
      setStatus("error");
    }
  }, []);

  useEffect(() => {
    async function sync() {
      if (authStatus === "loading") {
        setStatus("loading");
        return;
      }
      if (authStatus === "guest") {
        setItems([]);
        setSubtotal(0);
        setStatus("guest");
        return;
      }
      await refresh();
    }
    void sync();
  }, [authStatus, refresh]);

  const addItem = useCallback(async (competitionId: number, quantity = 1) => {
    const { data } = await cartClient.addCartItem(competitionId, quantity);
    setItems(data.items.map(adaptItem));
    setSubtotal(data.subtotal / 100);
  }, []);

  const removeItem = useCallback(async (itemId: number) => {
    const { data } = await cartClient.removeCartItem(itemId);
    setItems(data.items.map(adaptItem));
    setSubtotal(data.subtotal / 100);
  }, []);

  const setQuantity = useCallback(
    async (itemId: number, quantity: number) => {
      if (quantity <= 0) {
        await removeItem(itemId);
        return;
      }
      const { data } = await cartClient.updateCartItem(itemId, quantity);
      setItems(data.items.map(adaptItem));
      setSubtotal(data.subtotal / 100);
    },
    [removeItem],
  );

  const clear = useCallback(async () => {
    for (const item of items) {
      await cartClient.removeCartItem(item.id);
    }
    await refresh();
  }, [items, refresh]);

  const value: CartValue = {
    items,
    count: items.reduce((n, i) => n + i.quantity, 0),
    subtotal,
    total: subtotal, // no discount/promotion logic — see docs/SCHEMA_DESIGN_P5.md
    status,
    hydrated: status !== "loading",
    addItem,
    setQuantity,
    removeItem,
    clear,
    refresh,
  };

  return <CartContext.Provider value={value}>{children}</CartContext.Provider>;
}

export function useCart(): CartValue {
  const ctx = useContext(CartContext);
  if (!ctx) throw new Error("useCart must be used within <CartProvider>");
  return ctx;
}
