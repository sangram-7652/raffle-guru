import { afterEach, describe, expect, test, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import EntrySelector from "@/components/competition/EntrySelector";
import { ApiValidationError } from "@/lib/api/http";
import type { Competition } from "@/data/competitions";

const push = vi.fn();
vi.mock("next/navigation", () => ({
  useRouter: () => ({ push }),
}));

const authState: { status: "guest" | "authenticated" } = { status: "authenticated" };
vi.mock("@/lib/auth/AuthContext", () => ({
  useAuth: () => authState,
}));

const addItem = vi.fn();
vi.mock("@/components/cart/CartContext", () => ({
  useCart: () => ({ addItem }),
}));

const competition: Competition = {
  id: "10",
  slug: "bmw-m4",
  title: "BMW M4 Competition",
  blurb: "",
  image: "",
  badge: "",
  category: "Cars",
  entryPrice: 5,
  soldPct: 40,
  drawLabel: "Fri 8PM",
  prize: "BMW M4",
  prizeValue: 60000,
  images: [],
  totalEntries: 10000,
  drawDate: "2026-10-01T20:00:00Z",
  status: "live",
  featured: false,
  description: [],
  prizeDetails: [],
  rules: [],
  question: { prompt: "2+2?", options: ["3", "4"], answerIndex: -1 },
  faqs: [],
};

describe("EntrySelector", () => {
  afterEach(() => {
    vi.clearAllMocks();
    authState.status = "authenticated";
  });

  test("redirects a guest to login instead of adding to the cart", async () => {
    authState.status = "guest";
    const user = userEvent.setup();
    render(<EntrySelector competition={competition} />);

    await user.click(screen.getByRole("button", { name: /Add to basket/ }));

    expect(push).toHaveBeenCalledWith("/login?redirect=%2Fcompetitions%2Fbmw-m4");
    expect(addItem).not.toHaveBeenCalled();
  });

  test("adds the selected quantity to the real cart for a signed-in user", async () => {
    addItem.mockResolvedValueOnce(undefined);
    const user = userEvent.setup();
    render(<EntrySelector competition={competition} />);

    await user.click(screen.getByRole("button", { name: /Add to basket/ }));

    expect(addItem).toHaveBeenCalledWith(10, 1);
    expect(await screen.findByText("Added to basket")).toBeDefined();
  });

  test("surfaces a validation error instead of pretending the item was added", async () => {
    addItem.mockRejectedValueOnce(
      new ApiValidationError("Validation failed.", {
        competition_id: ["This competition is no longer available."],
      }),
    );
    const user = userEvent.setup();
    render(<EntrySelector competition={competition} />);

    await user.click(screen.getByRole("button", { name: /Add to basket/ }));

    expect(await screen.findByText("This competition is no longer available.")).toBeDefined();
  });

  // -- P8: server-controlled lifecycle status, never invented client-side --------------------------------------------------

  test("shows 'Sold out' and hides the entry form for a sold-out competition", () => {
    render(<EntrySelector competition={{ ...competition, status: "sold-out" }} />);

    expect(screen.getByText("Sold out")).toBeDefined();
    expect(screen.queryByRole("button", { name: /Add to basket/ })).toBeNull();
  });

  test("shows 'Closed' (never 'Sold out' or 'Drawn') for a closed competition", () => {
    render(<EntrySelector competition={{ ...competition, status: "closed" }} />);

    expect(screen.getByText("Closed")).toBeDefined();
    expect(screen.queryByText("Sold out")).toBeNull();
    expect(screen.queryByText("Drawn")).toBeNull();
    expect(screen.queryByRole("button", { name: /Add to basket/ })).toBeNull();
  });

  test("shows 'Draw pending' for a competition awaiting its draw", () => {
    render(<EntrySelector competition={{ ...competition, status: "draw-pending" }} />);

    expect(screen.getByText("Draw pending")).toBeDefined();
    expect(screen.queryByRole("button", { name: /Add to basket/ })).toBeNull();
  });

  test("never claims a draw happened before status is actually 'drawn'", () => {
    render(<EntrySelector competition={{ ...competition, status: "closed" }} />);

    expect(screen.queryByText(/^Drawn$/)).toBeNull();
  });

  // -- P9: winning ticket number --------------------------------------------------------------

  test("shows the winning ticket number once a competition is actually drawn", () => {
    render(
      <EntrySelector
        competition={{ ...competition, status: "drawn", winningTicketNumber: 4821 }}
      />,
    );

    expect(screen.getByText("Winning ticket: #4821")).toBeDefined();
  });

  test("shows no winning ticket number for a closed (not yet drawn) competition", () => {
    render(<EntrySelector competition={{ ...competition, status: "closed" }} />);

    expect(screen.queryByText(/Winning ticket/)).toBeNull();
  });
});
