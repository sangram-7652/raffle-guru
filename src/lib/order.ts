/**
 * Mock order handoff between checkout and the success page. Frontend only —
 * the "order" lives in sessionStorage for one navigation and is not sent
 * anywhere.
 */
import type { CartItem } from "@/components/cart/CartContext";

export type MockOrder = {
  reference: string;
  placedAt: string;
  email: string;
  name: string;
  items: {
    slug: string;
    title: string;
    quantity: number;
    lineTotal: number;
    firstTicket: number;
  }[];
  entryCount: number;
  subtotal: number;
  discount: number;
  promoCode: string | null;
  total: number;
};

const KEY = "raffleguru.lastOrder.v1";

export function buildMockOrder(input: {
  items: CartItem[];
  subtotal: number;
  discount: number;
  total: number;
  promoCode: string | null;
  email: string;
  name: string;
}): MockOrder {
  const ref = `RG-${Math.floor(100000 + Math.random() * 899999)}`;
  let ticketCursor = 10000 + Math.floor(Math.random() * 5000);
  const items = input.items.map((i) => {
    const firstTicket = ticketCursor;
    ticketCursor += i.quantity;
    return {
      slug: i.slug,
      title: i.title,
      quantity: i.quantity,
      lineTotal: i.entryPrice * i.quantity,
      firstTicket,
    };
  });
  return {
    reference: ref,
    placedAt: new Date().toISOString(),
    email: input.email,
    name: input.name,
    items,
    entryCount: input.items.reduce((n, i) => n + i.quantity, 0),
    subtotal: input.subtotal,
    discount: input.discount,
    promoCode: input.promoCode,
    total: input.total,
  };
}

export function saveMockOrder(order: MockOrder) {
  try {
    window.sessionStorage.setItem(KEY, JSON.stringify(order));
  } catch {
    /* ignore */
  }
}

export function readMockOrder(): MockOrder | null {
  try {
    const raw = window.sessionStorage.getItem(KEY);
    return raw ? (JSON.parse(raw) as MockOrder) : null;
  } catch {
    return null;
  }
}
