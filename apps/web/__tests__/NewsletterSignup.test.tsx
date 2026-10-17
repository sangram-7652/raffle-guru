import { afterEach, describe, expect, test, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import NewsletterSignup from "@/components/layout/NewsletterSignup";

const subscribeToNewsletter = vi.fn();
vi.mock("@/lib/newsletter/client", () => ({
  subscribeToNewsletter: (...args: unknown[]) => subscribeToNewsletter(...args),
}));

const getPromotion = vi.fn();
const getPromotionClaim = vi.fn();
const claimPromotion = vi.fn();
vi.mock("@/lib/promotions/client", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@/lib/promotions/client")>();
  return {
    ...actual,
    getPromotion: (...args: unknown[]) => getPromotion(...args),
    getPromotionClaim: (...args: unknown[]) => getPromotionClaim(...args),
    claimPromotion: (...args: unknown[]) => claimPromotion(...args),
  };
});

type AuthState = {
  status: "loading" | "guest" | "authenticated";
  user: { id: number; name: string; email: string; email_verified_at: string | null } | null;
};
const authState: AuthState = { status: "guest", user: null };
vi.mock("@/lib/auth/AuthContext", () => ({
  useAuth: () => authState,
}));

function setAuth(state: AuthState) {
  authState.status = state.status;
  authState.user = state.user;
}

const verifiedUser = { id: 1, name: "Jane", email: "jane@example.com", email_verified_at: "2026-01-01T00:00:00Z" };
const unverifiedUser = { id: 2, name: "Jo", email: "jo@example.com", email_verified_at: null };

/**
 * The plain subscribe-form button and the claim-panel button share the
 * exact same accessible name ("Claim Your Free Entry"), and the
 * subscribe form renders first (synchronously, before the async
 * getPromotion()/getPromotionClaim() checks resolve) — so a bare
 * findByRole would race and grab the wrong one. Wait for the
 * getPromotionClaim check to have actually run (proof the component
 * has settled into claim mode) and for the button it then renders to
 * become enabled (proof that check has resolved) before returning it.
 */
async function findClaimButton() {
  await waitFor(() => expect(getPromotionClaim).toHaveBeenCalled());
  const button = await screen.findByRole("button", { name: /claim your free entry/i });
  await waitFor(() => expect(button).toBeEnabled());
  return button;
}

describe("NewsletterSignup", () => {
  afterEach(() => {
    vi.clearAllMocks();
    setAuth({ status: "guest", user: null });
  });

  test("renders the client's offer copy", () => {
    getPromotion.mockResolvedValue(null);
    render(<NewsletterSignup />);

    expect(screen.getByText("Receive 1 Free Ticket & Entry")).toBeDefined();
    expect(screen.getByText("Enter your email and never miss a competition.")).toBeDefined();
  });

  describe("no promotion configured (or not open) — plain subscribe form", () => {
    test("rejects an empty submission without calling the API", async () => {
      getPromotion.mockResolvedValue(null);
      const user = userEvent.setup();
      render(<NewsletterSignup />);

      await user.click(screen.getByRole("button", { name: /claim your free entry/i }));

      expect(await screen.findByText("Enter your email address.")).toBeDefined();
      expect(subscribeToNewsletter).not.toHaveBeenCalled();
    });

    test("submits a valid email and shows a success state that only confirms subscription, never a ticket", async () => {
      getPromotion.mockResolvedValue({ slug: "welcome-ticket", name: "Welcome", is_open: false });
      subscribeToNewsletter.mockResolvedValueOnce({ data: { message: "ok" } });
      const user = userEvent.setup();
      render(<NewsletterSignup />);

      await user.type(screen.getByLabelText("Email address"), "jane@example.com");
      await user.click(screen.getByRole("button", { name: /claim your free entry/i }));

      const status = await screen.findByRole("status");
      expect(status.textContent).toMatch(/you're on the list/i);
      expect(status.textContent).not.toMatch(/ticket/i);
      expect(subscribeToNewsletter).toHaveBeenCalledWith({ email: "jane@example.com" });
      expect(claimPromotion).not.toHaveBeenCalled();
    });

    test("an API failure on getPromotion falls back to the plain subscribe form, not an error", async () => {
      getPromotion.mockRejectedValue(new Error("network down"));
      render(<NewsletterSignup />);

      expect(await screen.findByLabelText("Email address")).toBeDefined();
    });
  });

  describe("promotion open, guest visitor", () => {
    test("after subscribing, offers a login/register link to claim the ticket", async () => {
      getPromotion.mockResolvedValue({ slug: "welcome-ticket", name: "Welcome", is_open: true });
      subscribeToNewsletter.mockResolvedValueOnce({ data: { message: "ok" } });
      const user = userEvent.setup();
      render(<NewsletterSignup />);

      await user.type(await screen.findByLabelText("Email address"), "jane@example.com");
      await user.click(screen.getByRole("button", { name: /claim your free entry/i }));

      await screen.findByRole("status");
      expect(screen.getByRole("link", { name: "Log in" })).toBeDefined();
      expect(screen.getByRole("link", { name: "create a free account" })).toBeDefined();
    });
  });

  describe("promotion open, authenticated + verified", () => {
    test("shows a claim button (no email field) when no prior claim exists", async () => {
      setAuth({ status: "authenticated", user: verifiedUser });
      getPromotion.mockResolvedValue({ slug: "welcome-ticket", name: "Welcome", is_open: true });
      getPromotionClaim.mockResolvedValue(null);
      render(<NewsletterSignup />);

      await waitFor(() => expect(getPromotionClaim).toHaveBeenCalled());
      expect(screen.queryByLabelText("Email address")).toBeNull();
      expect(screen.getByRole("button", { name: /claim your free entry/i })).toBeDefined();
    });

    test("clicking claim calls the API and shows a real success state only after it confirms", async () => {
      setAuth({ status: "authenticated", user: verifiedUser });
      getPromotion.mockResolvedValue({ slug: "welcome-ticket", name: "Welcome", is_open: true });
      getPromotionClaim.mockResolvedValue(null);
      claimPromotion.mockResolvedValue({ status: "fulfilled", failure_reason: null, entry_id: 7 });
      const user = userEvent.setup();
      render(<NewsletterSignup />);

      const button = await findClaimButton();
      await user.click(button);

      const status = await screen.findByRole("status");
      expect(status.textContent).toMatch(/claimed your free ticket/i);
      expect(claimPromotion).toHaveBeenCalledWith("welcome-ticket");
    });

    test("an already-fulfilled claim shows the success state immediately, without a click", async () => {
      setAuth({ status: "authenticated", user: verifiedUser });
      getPromotion.mockResolvedValue({ slug: "welcome-ticket", name: "Welcome", is_open: true });
      getPromotionClaim.mockResolvedValue({ status: "fulfilled", failure_reason: null, entry_id: 3 });
      render(<NewsletterSignup />);

      const status = await screen.findByRole("status");
      expect(status.textContent).toMatch(/claimed your free ticket/i);
      expect(claimPromotion).not.toHaveBeenCalled();
    });

    test("an ineligible/exhausted claim shows the real failure reason, not a false success", async () => {
      setAuth({ status: "authenticated", user: verifiedUser });
      getPromotion.mockResolvedValue({ slug: "welcome-ticket", name: "Welcome", is_open: true });
      getPromotionClaim.mockResolvedValue(null);
      claimPromotion.mockResolvedValue({
        status: "failed",
        failure_reason: "This offer has reached its claim limit.",
        entry_id: null,
      });
      const user = userEvent.setup();
      render(<NewsletterSignup />);

      const button = await findClaimButton();
      await user.click(button);

      expect(await screen.findByText("This offer has reached its claim limit.")).toBeDefined();
      expect(screen.queryByRole("status")).toBeNull();
    });

    test("clicking claim twice in a row (double-click) only calls the API once while in flight", async () => {
      let resolveClaim: (value: { status: string; failure_reason: null; entry_id: number }) => void = () => {};
      claimPromotion.mockImplementation(
        () =>
          new Promise((resolve) => {
            resolveClaim = resolve;
          }),
      );
      setAuth({ status: "authenticated", user: verifiedUser });
      getPromotion.mockResolvedValue({ slug: "welcome-ticket", name: "Welcome", is_open: true });
      getPromotionClaim.mockResolvedValue(null);
      const user = userEvent.setup();
      render(<NewsletterSignup />);

      const button = await findClaimButton();
      await user.click(button);
      expect(button).toBeDisabled();
      await user.click(button);

      expect(claimPromotion).toHaveBeenCalledTimes(1);
      resolveClaim({ status: "fulfilled", failure_reason: null, entry_id: 1 });
    });
  });

  describe("promotion open, authenticated but unverified", () => {
    test("asks the user to verify their email before claiming — never lets them claim unverified", async () => {
      setAuth({ status: "authenticated", user: unverifiedUser });
      getPromotion.mockResolvedValue({ slug: "welcome-ticket", name: "Welcome", is_open: true });
      render(<NewsletterSignup />);

      expect(await screen.findByText(/verify your email to claim/i)).toBeDefined();
      expect(screen.queryByRole("button", { name: /claim your free entry/i })).toBeNull();
      expect(getPromotionClaim).not.toHaveBeenCalled();
      expect(claimPromotion).not.toHaveBeenCalled();
    });
  });
});
