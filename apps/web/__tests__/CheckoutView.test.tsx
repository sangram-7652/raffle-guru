import { afterEach, describe, expect, test, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import CheckoutView from "@/components/checkout/CheckoutView";
import { ApiValidationError } from "@/lib/api/http";

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

async function fillValidForm(user: ReturnType<typeof userEvent.setup>) {
  await user.type(screen.getByLabelText(/First name/), "Jane");
  await user.type(screen.getByLabelText(/Last name/), "Doe");
  await user.type(screen.getByLabelText(/^Email/), "jane@example.com");
  await user.type(screen.getByLabelText(/Phone/), "07700900123");
  const dob = screen.getByLabelText(/Date of birth/) as HTMLInputElement;
  await user.type(dob, "1990-01-01");
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

  test("places a real order and navigates to the order-success page with its reference", async () => {
    createOrder.mockResolvedValueOnce({ data: { reference: "RG-482915" } });
    const user = userEvent.setup();
    render(<CheckoutView />);

    await fillValidForm(user);
    await user.click(screen.getByRole("button", { name: /Place order/ }));

    await waitFor(() => expect(createOrder).toHaveBeenCalledTimes(1));
    await waitFor(() => expect(refresh).toHaveBeenCalledTimes(1));
    expect(push).toHaveBeenCalledWith("/order/success?reference=RG-482915");
  });

  test("never claims payment succeeded — copy says payment is pending", () => {
    render(<CheckoutView />);

    expect(screen.queryByText(/payment successful/i)).toBeNull();
    expect(
      screen.getByText(/No payment is taken — your order is created and marked/),
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
  });
});
