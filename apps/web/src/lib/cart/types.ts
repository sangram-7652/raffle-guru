/** Types matching the real backend API contract exactly (see
 * docs/ORDERS_API.md) — money stays in minor units (pence) here; the
 * cart context converts to pounds for display, same convention as
 * @/lib/competitions/adapter. */

export type ApiCartItem = {
  id: number;
  quantity: number;
  unit_price: number;
  currency: string;
  line_total: number;
  competition: {
    id: number;
    slug: string;
    title: string;
    image: string | null;
    draw_at: string | null;
  };
};

export type ApiCart = {
  id: number;
  currency: string;
  items: ApiCartItem[];
  subtotal: number;
  count: number;
};

export type SingleResponse<T> = { data: T };
