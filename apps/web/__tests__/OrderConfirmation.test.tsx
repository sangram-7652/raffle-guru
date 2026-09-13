import { afterEach, describe, expect, test, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import OrderConfirmation from "@/components/checkout/OrderConfirmation";

let searchParams = new URLSearchParams();

vi.mock("next/navigation", () => ({
  useSearchParams: () => searchParams,
}));

const getOrder = vi.fn();
vi.mock("@/lib/orders/client", () => ({
  getOrder: (...args: unknown[]) => getOrder(...args),
}));

const order = {
  id: 1,
  reference: "RG-482915",
  status: "pending",
  currency: "GBP",
  subtotal: 1000,
  total: 1000,
  created_at: "2026-09-13T12:00:00Z",
  items: [
    {
      id: 1,
      competition_id: 10,
      competition_title: "BMW M4 Competition",
      competition_slug: "bmw-m4",
      quantity: 5,
      unit_price: 200,
      currency: "GBP",
      line_total: 1000,
    },
  ],
};

describe("OrderConfirmation", () => {
  afterEach(() => {
    vi.clearAllMocks();
  });

  test("shows the order in a pending-payment state, never claiming payment succeeded", async () => {
    searchParams = new URLSearchParams({ reference: "RG-482915" });
    getOrder.mockResolvedValueOnce(order);

    render(<OrderConfirmation />);

    expect(await screen.findByText("Order created — payment pending")).toBeDefined();
    expect(screen.getByText("BMW M4 Competition")).toBeDefined();
    expect(screen.getByText("Total (payment pending)")).toBeDefined();
    expect(screen.queryByText(/payment successful/i)).toBeNull();
    expect(screen.queryByText(/total paid/i)).toBeNull();
    expect(getOrder).toHaveBeenCalledWith("RG-482915");
  });

  test("shows a not-found state without leaking whether the order/reference exists for another user", async () => {
    searchParams = new URLSearchParams({ reference: "RG-000000" });
    getOrder.mockResolvedValueOnce(null);

    render(<OrderConfirmation />);

    expect(await screen.findByText("Order not found")).toBeDefined();
  });

  test("shows a not-found state when no reference is present in the URL", async () => {
    searchParams = new URLSearchParams();

    render(<OrderConfirmation />);

    expect(await screen.findByText("Order not found")).toBeDefined();
    expect(getOrder).not.toHaveBeenCalled();
  });
});
