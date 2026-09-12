"use client";

import {
  createContext,
  useCallback,
  useContext,
  useMemo,
  useSyncExternalStore,
  type ReactNode,
} from "react";

/**
 * Frontend-only cart. localStorage is the single source of truth; React
 * reads it through useSyncExternalStore (no effects, no hydration mismatch).
 * There is NO backend, no real basket and no ticket reservation. Promo
 * codes are a small hardcoded lookup for the discount UI.
 */

export type CartItem = {
  slug: string;
  title: string;
  image: string;
  entryPrice: number;
  quantity: number;
  drawLabel: string;
};

export type Promo = { code: string; label: string; rate: number };

const PROMOS: Promo[] = [
  { code: "RAFFLE10", label: "10% off your entries", rate: 0.1 },
  { code: "GURU5", label: "5% welcome discount", rate: 0.05 },
];

const STORAGE_KEY = "raffleguru.cart.v1";
const EVENT = "raffleguru:cart";

type StoredState = { items: CartItem[]; promoCode: string | null };

const EMPTY: StoredState = { items: [], promoCode: null };

/* ---- external store ------------------------------------------------ */

let cache: StoredState = EMPTY;
let cacheRaw: string | null = null;

function read(): StoredState {
  if (typeof window === "undefined") return EMPTY;
  let raw: string | null = null;
  try {
    raw = window.localStorage.getItem(STORAGE_KEY);
  } catch {
    return EMPTY;
  }
  if (raw === cacheRaw) return cache;
  cacheRaw = raw;
  if (!raw) {
    cache = EMPTY;
    return cache;
  }
  try {
    const parsed = JSON.parse(raw) as StoredState;
    cache = {
      items: Array.isArray(parsed.items) ? parsed.items : [],
      promoCode: parsed.promoCode ?? null,
    };
  } catch {
    cache = EMPTY;
  }
  return cache;
}

function write(next: StoredState) {
  try {
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify(next));
  } catch {
    /* storage unavailable — cart won't persist this session */
  }
  cacheRaw = null; // force re-parse on next read
  window.dispatchEvent(new Event(EVENT));
}

function update(fn: (prev: StoredState) => StoredState) {
  write(fn(read()));
}

function subscribe(cb: () => void) {
  window.addEventListener(EVENT, cb);
  window.addEventListener("storage", cb);
  return () => {
    window.removeEventListener(EVENT, cb);
    window.removeEventListener("storage", cb);
  };
}

/* ---- context ----------------------------------------------------- */

type CartValue = {
  items: CartItem[];
  count: number;
  subtotal: number;
  discount: number;
  total: number;
  promo: Promo | null;
  hydrated: boolean;
  addItem: (item: Omit<CartItem, "quantity">, quantity?: number) => void;
  setQuantity: (slug: string, quantity: number) => void;
  removeItem: (slug: string) => void;
  clear: () => void;
  applyPromo: (code: string) => boolean;
  removePromo: () => void;
};

const CartContext = createContext<CartValue | null>(null);

export function CartProvider({ children }: { children: ReactNode }) {
  const state = useSyncExternalStore(
    subscribe,
    read,
    () => EMPTY,
  );
  const hydrated = useSyncExternalStore(
    subscribe,
    () => true,
    () => false,
  );

  const addItem = useCallback(
    (item: Omit<CartItem, "quantity">, quantity = 1) =>
      update((prev) => {
        const existing = prev.items.find((i) => i.slug === item.slug);
        const items = existing
          ? prev.items.map((i) =>
              i.slug === item.slug
                ? { ...i, quantity: Math.min(999, i.quantity + quantity) }
                : i,
            )
          : [...prev.items, { ...item, quantity: Math.max(1, quantity) }];
        return { ...prev, items };
      }),
    [],
  );

  const setQuantity = useCallback(
    (slug: string, quantity: number) =>
      update((prev) => ({
        ...prev,
        items:
          quantity <= 0
            ? prev.items.filter((i) => i.slug !== slug)
            : prev.items.map((i) =>
                i.slug === slug
                  ? { ...i, quantity: Math.min(999, Math.round(quantity)) }
                  : i,
              ),
      })),
    [],
  );

  const removeItem = useCallback(
    (slug: string) =>
      update((prev) => ({
        ...prev,
        items: prev.items.filter((i) => i.slug !== slug),
      })),
    [],
  );

  const clear = useCallback(() => write(EMPTY), []);

  const applyPromo = useCallback((code: string) => {
    const match = PROMOS.find(
      (p) => p.code.toLowerCase() === code.trim().toLowerCase(),
    );
    if (!match) return false;
    update((prev) => ({ ...prev, promoCode: match.code }));
    return true;
  }, []);

  const removePromo = useCallback(
    () => update((prev) => ({ ...prev, promoCode: null })),
    [],
  );

  const value = useMemo<CartValue>(() => {
    const promo = PROMOS.find((p) => p.code === state.promoCode) ?? null;
    const subtotal = state.items.reduce(
      (sum, i) => sum + i.entryPrice * i.quantity,
      0,
    );
    const discount = promo ? subtotal * promo.rate : 0;
    return {
      items: state.items,
      count: state.items.reduce((n, i) => n + i.quantity, 0),
      subtotal,
      discount,
      total: Math.max(0, subtotal - discount),
      promo,
      hydrated,
      addItem,
      setQuantity,
      removeItem,
      clear,
      applyPromo,
      removePromo,
    };
  }, [
    state,
    hydrated,
    addItem,
    setQuantity,
    removeItem,
    clear,
    applyPromo,
    removePromo,
  ]);

  return <CartContext.Provider value={value}>{children}</CartContext.Provider>;
}

export function useCart(): CartValue {
  const ctx = useContext(CartContext);
  if (!ctx) throw new Error("useCart must be used within <CartProvider>");
  return ctx;
}

export { PROMOS };

