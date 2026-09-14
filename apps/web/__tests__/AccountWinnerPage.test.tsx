import { afterEach, describe, expect, test, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import AccountWinnerPage from "@/app/account/winner/page";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace: vi.fn() }),
  usePathname: () => "/account/winner",
}));

const authState: { status: "loading" | "guest" | "authenticated" } = { status: "authenticated" };
vi.mock("@/lib/auth/AuthContext", () => ({
  useAuth: () => authState,
}));

const getCurrentWinner = vi.fn();
const submitWinnerClaim = vi.fn();
vi.mock("@/lib/winners/client", () => ({
  getCurrentWinner: (...args: unknown[]) => getCurrentWinner(...args),
  submitWinnerClaim: (...args: unknown[]) => submitWinnerClaim(...args),
}));

const winner = {
  id: 1,
  status: "contacted",
  competition: { id: 10, slug: "bmw-m4", title: "BMW M4 Competition" },
  winning_ticket_number: 42,
  prize: { title: "BMW M4", value: 5000000, currency: "GBP" },
  contacted_at: "2026-09-14T12:00:00Z",
  verified_at: null,
  claim_approved_at: null,
  delivery_pending_at: null,
  delivered_at: null,
  completed_at: null,
  claim: null,
  delivery: null,
  created_at: "2026-09-14T10:00:00Z",
};

describe("AccountWinnerPage", () => {
  afterEach(() => {
    vi.clearAllMocks();
    authState.status = "authenticated";
  });

  test("redirects a guest instead of loading winner status", () => {
    authState.status = "guest";
    render(<AccountWinnerPage />);

    expect(screen.getByText("Loading…")).toBeDefined();
    expect(getCurrentWinner).not.toHaveBeenCalled();
  });

  test("shows an empty state when the user has never won", async () => {
    getCurrentWinner.mockResolvedValueOnce(null);

    render(<AccountWinnerPage />);

    expect(await screen.findByText("No wins yet")).toBeDefined();
  });

  test("shows an error state with a retry action when loading fails", async () => {
    getCurrentWinner.mockRejectedValueOnce(new Error("network"));

    render(<AccountWinnerPage />);

    expect(await screen.findByText("Couldn't load your winner status")).toBeDefined();
  });

  test("shows the winner's competition, prize, and ticket number — never mock data", async () => {
    getCurrentWinner.mockResolvedValueOnce(winner);

    render(<AccountWinnerPage />);

    expect(await screen.findByText("BMW M4 Competition")).toBeDefined();
    expect(screen.getByText(/Winning ticket #42/)).toBeDefined();
  });

  test("offers the claim form once contacted, and never before", async () => {
    getCurrentWinner.mockResolvedValueOnce({ ...winner, status: "pending_contact" });

    render(<AccountWinnerPage />);

    await screen.findByText("BMW M4 Competition");
    expect(screen.queryByText("Submit your claim")).toBeNull();
    expect(screen.getByText(/We'll be in touch/)).toBeDefined();
  });

  test("submits a claim and refreshes to show it as submitted", async () => {
    const user = userEvent.setup();
    getCurrentWinner
      .mockResolvedValueOnce(winner)
      .mockResolvedValueOnce({
        ...winner,
        status: "verification_pending",
        claim: { id: 1, status: "submitted", submitted_at: "2026-09-14T13:00:00Z", verified_at: null, rejected_at: null, rejection_reason: null, notes: "hi" },
      });
    submitWinnerClaim.mockResolvedValueOnce({ data: { id: 1, status: "submitted" } });

    render(<AccountWinnerPage />);

    await screen.findByText("Submit your claim");
    await user.click(screen.getByRole("button", { name: "Submit claim" }));

    expect(await screen.findByText("Claim submitted")).toBeDefined();
    expect(submitWinnerClaim).toHaveBeenCalledWith(1, "");
  });

  test("never renders a control for a server-only transition (verify/approve/deliver)", async () => {
    getCurrentWinner.mockResolvedValueOnce(winner);

    render(<AccountWinnerPage />);

    await screen.findByText("BMW M4 Competition");
    expect(screen.queryByText(/mark verified/i)).toBeNull();
    expect(screen.queryByText(/approve claim/i)).toBeNull();
    expect(screen.queryByText(/mark delivered/i)).toBeNull();
  });
});
