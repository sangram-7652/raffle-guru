import { afterEach, describe, expect, test, vi } from "vitest";
import { act, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import OrderConfirmation from "@/components/checkout/OrderConfirmation";
import type { ApiOrder } from "@/lib/orders/types";

let searchParams = new URLSearchParams();

vi.mock("next/navigation", () => ({
  useSearchParams: () => searchParams,
}));

const getOrder = vi.fn();
vi.mock("@/lib/orders/client", () => ({
  getOrder: (...args: unknown[]) => getOrder(...args),
}));

const pay = vi.fn();
const paymentState: { current: { status: string; message?: string } } = { current: { status: "idle" } };

vi.mock("@/lib/payments/useRazorpayCheckout", () => ({
  useRazorpayCheckout: () => ({ state: paymentState.current, pay, reset: vi.fn() }),
}));

function fakeOrder(overrides: Partial<ApiOrder> = {}): ApiOrder {
  return {
    id: 1,
    reference: "RG-482915",
    status: "pending",
    currency: "GBP",
    subtotal: 1000,
    total: 1000,
    created_at: "2026-09-13T12:00:00Z",
    payment: null,
    entries: [],
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
    ...overrides,
  };
}

describe("OrderConfirmation", () => {
  afterEach(() => {
    vi.clearAllMocks();
    paymentState.current = { status: "idle" };
  });

  test("shows a pending-payment state for a freshly created order, never claiming payment succeeded", async () => {
    searchParams = new URLSearchParams({ reference: "RG-482915" });
    getOrder.mockResolvedValueOnce(fakeOrder());

    render(<OrderConfirmation />);

    expect(await screen.findByText("Payment pending")).toBeDefined();
    expect(screen.getByText("BMW M4 Competition")).toBeDefined();
    expect(screen.getByText("Total (payment pending)")).toBeDefined();
    expect(screen.queryByText(/payment successful/i)).toBeNull();
    expect(screen.queryByText(/total paid/i)).toBeNull();
    expect(getOrder).toHaveBeenCalledWith("RG-482915");
  });

  test("shows a server-confirmed success state once the order is actually paid", async () => {
    searchParams = new URLSearchParams({ reference: "RG-482915" });
    getOrder.mockResolvedValueOnce(fakeOrder({ status: "paid", payment: {
      id: 1, gateway: "razorpay", status: "paid", amount: 1000, currency: "GBP",
      failure_message: null, paid_at: "2026-09-13T12:05:00Z", created_at: "2026-09-13T12:00:00Z",
    } }));

    render(<OrderConfirmation />);

    expect(await screen.findByText("Payment successful")).toBeDefined();
    expect(screen.getByText("Total paid")).toBeDefined();
    expect(screen.queryByRole("button", { name: /Complete payment/ })).toBeNull();
  });

  test("shows a failed-payment state with a retry action when the latest attempt failed", async () => {
    searchParams = new URLSearchParams({ reference: "RG-482915" });
    getOrder.mockResolvedValueOnce(fakeOrder({ payment: {
      id: 1, gateway: "razorpay", status: "failed", amount: 1000, currency: "GBP",
      failure_message: "Payment could not be verified.", paid_at: null, created_at: "2026-09-13T12:00:00Z",
    } }));

    render(<OrderConfirmation />);

    expect(await screen.findByText("Payment failed — retry payment")).toBeDefined();
    const retryButton = screen.getByRole("button", { name: /Complete payment/ });
    expect(retryButton).toBeDefined();

    const user = userEvent.setup();
    await user.click(retryButton);
    expect(pay).toHaveBeenCalledWith("RG-482915");
  });

  test("does not infer payment status from the URL — only from the fetched order", async () => {
    // A reference alone in the URL says nothing about payment state; the
    // component must wait for and use the server response.
    searchParams = new URLSearchParams({ reference: "RG-482915", status: "paid" });
    getOrder.mockResolvedValueOnce(fakeOrder({ status: "pending" }));

    render(<OrderConfirmation />);

    expect(await screen.findByText("Payment pending")).toBeDefined();
    expect(screen.queryByText("Payment successful")).toBeNull();
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

  test("shows a truthful allocation-in-progress state for a paid order with no entries yet — never a fake ticket", async () => {
    searchParams = new URLSearchParams({ reference: "RG-482915" });
    getOrder.mockResolvedValueOnce(fakeOrder({ status: "paid", entries: [] }));

    render(<OrderConfirmation />);

    expect(await screen.findByText("Allocating your tickets — this page updates automatically.")).toBeDefined();
    expect(screen.getByText("Ticket allocation in progress")).toBeDefined();
    expect(screen.queryByText(/^#\d/)).toBeNull();
  });

  test("shows real ticket numbers once entries have been allocated", async () => {
    searchParams = new URLSearchParams({ reference: "RG-482915" });
    getOrder.mockResolvedValueOnce(
      fakeOrder({
        status: "paid",
        entries: [
          {
            id: 1,
            competition: { id: 10, slug: "bmw-m4", title: "BMW M4 Competition" },
            order_reference: "RG-482915",
            quantity: 5,
            ticket_numbers: [1, 2, 3, 4, 5],
            allocated_at: "2026-09-13T12:01:00Z",
          },
        ],
      }),
    );

    render(<OrderConfirmation />);

    expect(await screen.findByText("#1, #2, #3, #4, #5")).toBeDefined();
    expect(screen.queryByText("Ticket allocation in progress")).toBeNull();
    expect(screen.queryByText("Allocating your tickets — this page updates automatically.")).toBeNull();
  });

  test("polls for allocation to complete while a paid order still has no entries", async () => {
    vi.useFakeTimers();
    try {
      searchParams = new URLSearchParams({ reference: "RG-482915" });
      getOrder
        .mockResolvedValueOnce(fakeOrder({ status: "paid", entries: [] }))
        .mockResolvedValueOnce(
          fakeOrder({
            status: "paid",
            entries: [
              {
                id: 1,
                competition: { id: 10, slug: "bmw-m4", title: "BMW M4 Competition" },
                order_reference: "RG-482915",
                quantity: 5,
                ticket_numbers: [1, 2, 3, 4, 5],
                allocated_at: "2026-09-13T12:01:00Z",
              },
            ],
          }),
        );

      render(<OrderConfirmation />);
      // Flush the initial load()'s promise/microtasks without advancing
      // the clock — fake timers are active, so findBy/waitFor's own
      // internal polling can't be used here.
      await act(async () => {
        await vi.advanceTimersByTimeAsync(0);
      });
      expect(getOrder).toHaveBeenCalledTimes(1);
      expect(screen.getByText("Ticket allocation in progress")).toBeDefined();

      await act(async () => {
        await vi.advanceTimersByTimeAsync(3000);
      });

      expect(getOrder).toHaveBeenCalledTimes(2);
      expect(screen.getByText("#1, #2, #3, #4, #5")).toBeDefined();
    } finally {
      vi.useRealTimers();
    }
  });
});
