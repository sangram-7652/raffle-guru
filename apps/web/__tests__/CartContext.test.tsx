import { describe, expect, test, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { CartProvider, useCart } from "@/components/cart/CartContext";

const authState: { status: "loading" | "guest" | "authenticated" } = { status: "loading" };

vi.mock("@/lib/auth/AuthContext", () => ({
  useAuth: () => authState,
}));

const getCart = vi.fn();
const addCartItem = vi.fn();
const updateCartItem = vi.fn();
const removeCartItem = vi.fn();

vi.mock("@/lib/cart/client", () => ({
  getCart: (...args: unknown[]) => getCart(...args),
  addCartItem: (...args: unknown[]) => addCartItem(...args),
  updateCartItem: (...args: unknown[]) => updateCartItem(...args),
  removeCartItem: (...args: unknown[]) => removeCartItem(...args),
}));

function apiItem(overrides: Partial<Record<string, unknown>> = {}) {
  return {
    id: 1,
    quantity: 2,
    unit_price: 500,
    currency: "GBP",
    line_total: 1000,
    competition: { id: 10, slug: "bmw-m4", title: "BMW M4", image: null, draw_at: null },
    ...overrides,
  };
}

function Probe() {
  const cart = useCart();
  if (cart.status === "loading") return <p>loading</p>;
  if (cart.status === "guest") return <p>guest</p>;
  if (cart.status === "error") return <p>error</p>;
  return (
    <div>
      <p>ready:{cart.items.length}</p>
      <p>subtotal:{cart.subtotal}</p>
      <button onClick={() => void cart.addItem(10, 1)}>add</button>
      <button onClick={() => void cart.setQuantity(1, 3)}>update</button>
      <button onClick={() => void cart.removeItem(1)}>remove</button>
    </div>
  );
}

describe("CartProvider", () => {
  test("stays in loading while auth is loading", () => {
    authState.status = "loading";
    render(
      <CartProvider>
        <Probe />
      </CartProvider>,
    );
    expect(screen.getByText("loading")).toBeDefined();
    expect(getCart).not.toHaveBeenCalled();
  });

  test("resolves to guest with an empty cart when unauthenticated", async () => {
    authState.status = "guest";
    render(
      <CartProvider>
        <Probe />
      </CartProvider>,
    );
    expect(await screen.findByText("guest")).toBeDefined();
    expect(getCart).not.toHaveBeenCalled();
  });

  test("loads the real cart for an authenticated user", async () => {
    authState.status = "authenticated";
    getCart.mockResolvedValueOnce({
      data: { id: 1, currency: "GBP", items: [apiItem()], subtotal: 1000, count: 2 },
    });

    render(
      <CartProvider>
        <Probe />
      </CartProvider>,
    );

    expect(await screen.findByText("ready:1")).toBeDefined();
    expect(screen.getByText("subtotal:10")).toBeDefined();
  });

  test("moves to the error state when the cart fails to load", async () => {
    authState.status = "authenticated";
    getCart.mockRejectedValueOnce(new Error("network"));

    render(
      <CartProvider>
        <Probe />
      </CartProvider>,
    );

    expect(await screen.findByText("error")).toBeDefined();
  });

  test("add/update/remove call the cart client and refresh local state", async () => {
    authState.status = "authenticated";
    getCart.mockResolvedValueOnce({
      data: { id: 1, currency: "GBP", items: [apiItem()], subtotal: 1000, count: 2 },
    });
    addCartItem.mockResolvedValueOnce({
      data: { id: 1, currency: "GBP", items: [apiItem(), apiItem({ id: 2 })], subtotal: 2000, count: 4 },
    });
    updateCartItem.mockResolvedValueOnce({
      data: { id: 1, currency: "GBP", items: [apiItem({ quantity: 3 })], subtotal: 1500, count: 3 },
    });
    removeCartItem.mockResolvedValueOnce({
      data: { id: 1, currency: "GBP", items: [], subtotal: 0, count: 0 },
    });

    const user = userEvent.setup();
    render(
      <CartProvider>
        <Probe />
      </CartProvider>,
    );
    await screen.findByText("ready:1");

    await user.click(screen.getByText("add"));
    expect(await screen.findByText("ready:2")).toBeDefined();
    expect(addCartItem).toHaveBeenCalledWith(10, 1);

    await user.click(screen.getByText("update"));
    expect(await screen.findByText("subtotal:15")).toBeDefined();
    expect(updateCartItem).toHaveBeenCalledWith(1, 3);

    await user.click(screen.getByText("remove"));
    expect(await screen.findByText("ready:0")).toBeDefined();
    expect(removeCartItem).toHaveBeenCalledWith(1);
  });
});
