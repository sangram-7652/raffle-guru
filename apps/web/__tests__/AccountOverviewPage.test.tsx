import { afterEach, describe, expect, test, vi } from "vitest";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import AccountPage from "@/app/account/page";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace: vi.fn(), push: vi.fn() }),
  usePathname: () => "/account",
}));

type AuthUser = { id: number; name: string; email: string; email_verified_at: string | null };
const authState: { status: "loading" | "guest" | "authenticated"; user: AuthUser | null } = {
  status: "authenticated",
  user: { id: 1, name: "Amara Okafor", email: "amara@example.com", email_verified_at: "2026-09-01T10:00:00Z" },
};
vi.mock("@/lib/auth/AuthContext", () => ({
  useAuth: () => ({ ...authState, logout: vi.fn() }),
}));

const resendVerification = vi.fn();
vi.mock("@/lib/auth/client", () => ({
  resendVerification: (...args: unknown[]) => resendVerification(...args),
}));

const listAllEntries = vi.fn();
vi.mock("@/lib/entries/client", () => ({
  listAllEntries: (...args: unknown[]) => listAllEntries(...args),
}));

const getCompetition = vi.fn();
vi.mock("@/lib/competitions/client", () => ({
  getCompetition: (...args: unknown[]) => getCompetition(...args),
}));

const listWinners = vi.fn();
vi.mock("@/lib/winners/client", () => ({
  listWinners: (...args: unknown[]) => listWinners(...args),
}));

const listOrders = vi.fn();
vi.mock("@/lib/orders/client", () => ({
  listOrders: (...args: unknown[]) => listOrders(...args),
}));

/** The figure shown on one summary card, read by its label. */
function summaryValue(label: string): string | null {
  const summary = screen.getByRole("region", { name: "Summary" });
  const card = within(summary).getByText(label).parentElement?.parentElement;
  return card?.lastElementChild?.textContent ?? null;
}

const page = (total: number) => ({ data: [], meta: { current_page: 1, last_page: 1, per_page: 10, total } });

const entry = {
  id: 1,
  competition: { id: 10, slug: "bmw-m4", title: "BMW M4 Competition", status: "active" },
  order_reference: "RG-482915",
  quantity: 3,
  ticket_numbers: [1, 2, 3],
  allocated_at: "2026-09-13T12:00:00Z",
};

const apiCompetition = {
  id: 10,
  slug: "bmw-m4",
  title: "BMW M4 Competition",
  short_description: "",
  description: null,
  category: "cars",
  status: "active",
  entry_price: 200,
  currency: "GBP",
  max_entries: 1000,
  max_entries_per_user: null,
  images: null,
  skill_question: null,
  starts_at: null,
  ends_at: null,
  draw_at: "2999-10-10T19:00:00Z",
  is_featured: false,
  prizes: [],
};

function setUpReady({ entries = [entry] as unknown[], wins = 0 } = {}) {
  listAllEntries.mockResolvedValue(entries);
  getCompetition.mockResolvedValue(apiCompetition);
  listWinners.mockResolvedValue(page(wins));
  listOrders.mockResolvedValue({ data: [], meta: page(0).meta });
}

describe("AccountOverviewPage", () => {
  afterEach(() => {
    vi.clearAllMocks();
    authState.status = "authenticated";
    authState.user = {
      id: 1,
      name: "Amara Okafor",
      email: "amara@example.com",
      email_verified_at: "2026-09-01T10:00:00Z",
    };
  });

  test("greets the customer by first name with the supporting line", async () => {
    setUpReady();
    render(<AccountPage />);

    expect(await screen.findByRole("heading", { level: 1, name: "Welcome back, Amara" })).toBeDefined();
    expect(screen.getByText("Track your entries, tickets and upcoming draws.")).toBeDefined();
  });

  test("a guest is redirected rather than shown the dashboard", () => {
    authState.status = "guest";
    authState.user = null;
    render(<AccountPage />);

    expect(screen.getByText("Loading…")).toBeDefined();
    expect(listAllEntries).not.toHaveBeenCalled();
  });

  test("no entries shows zero counts and the no-active-entries empty state", async () => {
    setUpReady({ entries: [], wins: 0 });
    render(<AccountPage />);

    expect(await screen.findByText("No active entries yet")).toBeDefined();
    expect(screen.getByText("Enter a competition and your tickets will appear here.")).toBeDefined();
    expect(screen.getByRole("link", { name: "Browse competitions" })).toBeDefined();

    expect(summaryValue("My entries")).toBe("0");
    expect(summaryValue("My tickets")).toBe("0");
    expect(summaryValue("My wins")).toBe("0");
  });

  test("entries show real counts, the active competition and its draw time", async () => {
    setUpReady({ entries: [entry], wins: 0 });
    render(<AccountPage />);

    const card = await screen.findByRole("heading", { level: 3, name: "BMW M4 Competition" });
    const article = card.closest("article") as HTMLElement;
    expect(within(article).getByText("3")).toBeDefined();
    expect(within(article).getByText("Open")).toBeDefined();
    expect(within(article).getByRole("link", { name: "View competition" }).getAttribute("href")).toBe(
      "/competitions/bmw-m4",
    );

    expect(summaryValue("My entries")).toBe("1");
    expect(summaryValue("My tickets")).toBe("3");
  });

  test("a competition with an unknown draw time is never given a made-up date", async () => {
    setUpReady({ entries: [entry] });
    getCompetition.mockResolvedValue({ ...apiCompetition, draw_at: null });
    render(<AccountPage />);

    expect((await screen.findAllByText("To be confirmed")).length).toBeGreaterThan(0);
  });

  test("no wins shows a zero win count, not a win message", async () => {
    setUpReady({ entries: [entry], wins: 0 });
    render(<AccountPage />);

    await screen.findByRole("heading", { level: 3, name: "BMW M4 Competition" });
    expect(summaryValue("My wins")).toBe("0");
    expect(screen.queryByText(/congratulations/i)).toBeNull();
  });

  test("wins available shows the real win count", async () => {
    setUpReady({ entries: [entry], wins: 2 });
    render(<AccountPage />);

    await screen.findByRole("heading", { level: 3, name: "BMW M4 Competition" });
    expect(summaryValue("My wins")).toBe("2");
  });

  test("a failed wins request shows a dash, never a fabricated zero", async () => {
    setUpReady({ entries: [entry] });
    listWinners.mockRejectedValue(new Error("network"));
    render(<AccountPage />);

    await screen.findByRole("heading", { level: 3, name: "BMW M4 Competition" });
    expect(summaryValue("My wins")).toBe("—");
  });

  test("a failed entries request shows an error state with a dash in the summary", async () => {
    listAllEntries.mockRejectedValue(new Error("network"));
    listWinners.mockResolvedValue(page(0));
    listOrders.mockResolvedValue({ data: [], meta: page(0).meta });
    render(<AccountPage />);

    expect(await screen.findByText("Couldn't load your entries")).toBeDefined();
    expect(summaryValue("My entries")).toBe("—");
  });

  test("a verified account shows no verification banner and a verified status", async () => {
    setUpReady();
    render(<AccountPage />);

    await screen.findByRole("heading", { level: 3, name: "BMW M4 Competition" });
    expect(screen.queryByText("Verify your email address")).toBeNull();
    expect(screen.getByText("Email verified")).toBeDefined();
  });

  test("an unverified account shows the verification banner with a working resend action", async () => {
    authState.user = { ...authState.user!, email_verified_at: null };
    setUpReady();
    resendVerification.mockResolvedValue({ message: "sent" });
    const user = userEvent.setup();
    render(<AccountPage />);

    expect(await screen.findByText("Verify your email address")).toBeDefined();
    expect(screen.getByText("Email not verified")).toBeDefined();

    await user.click(screen.getByRole("button", { name: "Resend verification email" }));

    expect(resendVerification).toHaveBeenCalledTimes(1);
    expect(await screen.findByText(/A new verification link is on its way/)).toBeDefined();
  });

  test("the account card links to account details and exposes no password or token", async () => {
    setUpReady();
    render(<AccountPage />);

    await screen.findByRole("heading", { level: 3, name: "BMW M4 Competition" });
    expect(screen.getByRole("link", { name: "Account details" }).getAttribute("href")).toBe("/account/details");
    expect(document.body.textContent).not.toMatch(/password|token|sanctum/i);
  });

  test("recent activity lists real orders, and says so when there are none", async () => {
    setUpReady();
    listOrders.mockResolvedValue({
      data: [
        {
          id: 5,
          reference: "RG-900001",
          status: "paid",
          currency: "GBP",
          subtotal: 1000,
          total: 1000,
          items: [],
          payment: null,
          entries: [],
          created_at: "2026-09-13T12:00:00Z",
        },
      ],
      meta: page(1).meta,
    });
    render(<AccountPage />);

    expect(await screen.findByText("Order RG-900001")).toBeDefined();
    expect(screen.getByText(/Paid/)).toBeDefined();
  });

  test("an empty order history shows a calm empty line, not a blank card", async () => {
    setUpReady({ entries: [] });
    render(<AccountPage />);

    expect(await screen.findByText("Your orders and entry updates will appear here.")).toBeDefined();
  });
});
