import { afterEach, describe, expect, test, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import CheckoutView from "@/components/checkout/CheckoutView";
import { ApiValidationError } from "@/lib/api/http";
import type { ApiOrder } from "@/lib/orders/types";

const push = vi.fn();
const replace = vi.fn();

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push, replace }),
}));

const authState: { status: "loading" | "guest" | "authenticated" } = { status: "authenticated" };

vi.mock("@/lib/auth/AuthContext", () => ({
  useAuth: () => authState,
}));

const createOrder = vi.fn();
vi.mock("@/lib/orders/client", () => ({
  createOrder: (...args: unknown[]) => createOrder(...args),
}));

const refresh = vi.fn();
const cartState: {
  hydrated: boolean;
  items: { id: number }[];
} = { hydrated: true, items: [{ id: 1 }] };

vi.mock("@/components/cart/CartContext", () => ({
  useCart: () => ({ ...cartState, refresh }),
}));

vi.mock("@/components/cart/OrderSummary", () => ({
  default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

const pay = vi.fn();
let capturedOnPaid: ((order: ApiOrder) => void) | null = null;
const paymentState: { current: { status: string; message?: string } } = { current: { status: "idle" } };

vi.mock("@/lib/payments/useRazorpayCheckout", () => ({
  useRazorpayCheckout: (onPaid: (order: ApiOrder) => void) => {
    capturedOnPaid = onPaid;
    return { state: paymentState.current, pay, reset: vi.fn() };
  },
}));

function fakeOrder(overrides: Partial<ApiOrder> = {}): ApiOrder {
  return {
    id: 1,
    reference: "RG-482915",
    status: "pending",
    currency: "GBP",
    subtotal: 500,
    total: 500,
    items: [
      {
        id: 1,
        competition_id: 10,
        competition_title: "BMW M4 Competition",
        competition_slug: "bmw-m4",
        quantity: 1,
        unit_price: 500,
        currency: "GBP",
        line_total: 500,
      },
    ],
    payment: null,
    entries: [],
    created_at: "2026-09-13T12:00:00Z",
    ...overrides,
  };
}

async function fillValidForm(user: ReturnType<typeof userEvent.setup>) {
  await user.type(screen.getByLabelText(/First name/), "Jane");
  await user.type(screen.getByLabelText(/Last name/), "Doe");
  await user.type(screen.getByLabelText(/^Email/), "jane@example.com");
  await user.type(screen.getByLabelText(/Phone/), "07700900123");
  // A native <input type="date"> doesn't reliably accept keystroke-by-
  // keystroke typing in jsdom — set the value directly, as a browser's
  // own date picker UI would.
  fireEvent.change(screen.getByLabelText(/Date of birth/), { target: { value: "1990-01-01" } });
  await user.type(screen.getByLabelText(/^Address/), "1 Example Street");
  await user.type(screen.getByLabelText(/Town/), "London");
  await user.type(screen.getByLabelText(/Postcode/), "SW1A 1AA");
  await user.click(screen.getByRole("checkbox"));
}

describe("CheckoutView", () => {
  afterEach(() => {
    vi.clearAllMocks();
    authState.status = "authenticated";
    cartState.hydrated = true;
    cartState.items = [{ id: 1 }];
    paymentState.current = { status: "idle" };
    capturedOnPaid = null;
  });

  test("redirects a guest to login with a checkout redirect target", async () => {
    authState.status = "guest";
    render(<CheckoutView />);

    await waitFor(() => expect(replace).toHaveBeenCalledWith("/login?redirect=%2Fcheckout"));
  });

  test("shows an empty-basket state instead of the form when the cart has no items", () => {
    cartState.items = [];
    render(<CheckoutView />);

    expect(screen.getByText("Nothing to check out")).toBeDefined();
    expect(screen.queryByLabelText(/First name/)).toBeNull();
  });

  test("places a real order, then hands off to Razorpay checkout for payment", async () => {
    createOrder.mockResolvedValueOnce({ data: fakeOrder() });
    const user = userEvent.setup();
    render(<CheckoutView />);

    await fillValidForm(user);
    await user.click(screen.getByRole("button", { name: /Place order/ }));

    await waitFor(() => expect(createOrder).toHaveBeenCalledTimes(1));
    await waitFor(() => expect(refresh).toHaveBeenCalledTimes(1));
    await waitFor(() => expect(pay).toHaveBeenCalledWith("RG-482915"));
    // Navigation only happens once the payment hook itself reports success
    // (a server-verified payment) — never merely on order creation.
    expect(push).not.toHaveBeenCalled();
    expect(screen.getByText("BMW M4 Competition")).toBeDefined();
  });

  test("navigates to order success only once the payment hook confirms a verified payment", async () => {
    createOrder.mockResolvedValueOnce({ data: fakeOrder() });
    const user = userEvent.setup();
    render(<CheckoutView />);

    await fillValidForm(user);
    await user.click(screen.getByRole("button", { name: /Place order/ }));
    await waitFor(() => expect(pay).toHaveBeenCalled());

    capturedOnPaid?.(fakeOrder({ status: "paid" }));

    expect(push).toHaveBeenCalledWith("/order/success?reference=RG-482915");
  });

  test("never claims payment succeeded — copy says payment is pending", () => {
    render(<CheckoutView />);

    expect(screen.queryByText(/payment successful/i)).toBeNull();
    expect(
      screen.getByText(/Your order isn.t marked paid until payment is verified/),
    ).toBeDefined();
  });

  test("surfaces an API validation error (e.g. an emptied cart) instead of navigating away", async () => {
    createOrder.mockRejectedValueOnce(
      new ApiValidationError("Validation failed.", { cart: ["Your basket is empty."] }),
    );
    const user = userEvent.setup();
    render(<CheckoutView />);

    await fillValidForm(user);
    await user.click(screen.getByRole("button", { name: /Place order/ }));

    expect(await screen.findByText("Your basket is empty.")).toBeDefined();
    expect(push).not.toHaveBeenCalled();
    expect(pay).not.toHaveBeenCalled();
  });

  test("shows a dismissed-checkout notice and lets the user retry without recreating the order", async () => {
    createOrder.mockResolvedValueOnce({ data: fakeOrder() });
    paymentState.current = { status: "dismissed" };
    const user = userEvent.setup();
    render(<CheckoutView />);

    await fillValidForm(user);
    await user.click(screen.getByRole("button", { name: /Place order/ }));
    await waitFor(() => expect(pay).toHaveBeenCalledTimes(1));

    expect(await screen.findByText(/Checkout closed — no payment was taken/)).toBeDefined();

    await user.click(screen.getByRole("button", { name: /Retry payment/ }));

    expect(createOrder).toHaveBeenCalledTimes(1);
    expect(pay).toHaveBeenCalledTimes(2);
    expect(pay).toHaveBeenLastCalledWith("RG-482915");
  });
});
