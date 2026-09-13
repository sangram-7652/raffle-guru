import { afterEach, describe, expect, test, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import CartView from "@/components/cart/CartView";

const clear = vi.fn();
const refresh = vi.fn();
const cartState: {
  items: { id: number; competitionId: number; slug: string; title: string; image: string; entryPrice: number; quantity: number; drawLabel: string }[];
  count: number;
  status: "loading" | "ready" | "guest" | "error";
} = { items: [], count: 0, status: "loading" };

vi.mock("@/components/cart/CartContext", () => ({
  useCart: () => ({ ...cartState, clear, refresh }),
}));

describe("CartView", () => {
  afterEach(() => {
    vi.clearAllMocks();
  });

  test("shows a loading skeleton while the cart is resolving", () => {
    cartState.status = "loading";
    render(<CartView />);
    expect(screen.queryByText("Your basket is empty")).toBeNull();
  });

  test("prompts a guest to sign in instead of showing basket contents", () => {
    cartState.status = "guest";
    render(<CartView />);
    expect(screen.getByText("Sign in to see your basket")).toBeDefined();
  });

  test("shows a retry action when the cart failed to load", async () => {
    cartState.status = "error";
    const user = userEvent.setup();
    render(<CartView />);

    expect(screen.getByText("Couldn't load your basket")).toBeDefined();
    await user.click(screen.getByRole("button", { name: "Retry" }));
    expect(refresh).toHaveBeenCalledTimes(1);
  });

  test("shows an empty-basket state when there are no items", () => {
    cartState.status = "ready";
    cartState.items = [];
    cartState.count = 0;
    render(<CartView />);
    expect(screen.getByText("Your basket is empty")).toBeDefined();
  });

  test("lists items and clears the basket on request", async () => {
    cartState.status = "ready";
    cartState.count = 2;
    cartState.items = [
      {
        id: 1,
        competitionId: 10,
        slug: "bmw-m4",
        title: "BMW M4 Competition",
        image: "",
        entryPrice: 5,
        quantity: 2,
        drawLabel: "Fri 8PM",
      },
    ];
    const user = userEvent.setup();
    render(<CartView />);

    expect(screen.getByText("BMW M4 Competition")).toBeDefined();
    await user.click(screen.getByText("Clear basket"));
    expect(clear).toHaveBeenCalledTimes(1);
  });
});
