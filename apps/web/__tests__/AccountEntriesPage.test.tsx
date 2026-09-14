import { afterEach, describe, expect, test, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import AccountEntriesPage from "@/app/account/entries/page";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace: vi.fn() }),
  usePathname: () => "/account/entries",
}));

const authState: { status: "loading" | "guest" | "authenticated" } = { status: "authenticated" };
vi.mock("@/lib/auth/AuthContext", () => ({
  useAuth: () => authState,
}));

const listEntries = vi.fn();
vi.mock("@/lib/entries/client", () => ({
  listEntries: (...args: unknown[]) => listEntries(...args),
}));

const entry = {
  id: 1,
  competition: { id: 10, slug: "bmw-m4", title: "BMW M4 Competition", status: "active" },
  order_reference: "RG-482915",
  quantity: 3,
  ticket_numbers: [1, 2, 3],
  allocated_at: "2026-09-13T12:00:00Z",
};

describe("AccountEntriesPage", () => {
  afterEach(() => {
    vi.clearAllMocks();
    authState.status = "authenticated";
  });

  test("redirects a guest instead of loading entries", () => {
    authState.status = "guest";
    render(<AccountEntriesPage />);

    expect(screen.getByText("Loading…")).toBeDefined();
    expect(listEntries).not.toHaveBeenCalled();
  });

  test("shows an empty state when there are no entries yet", async () => {
    listEntries.mockResolvedValueOnce({ data: [], meta: { current_page: 1, last_page: 1, per_page: 10, total: 0 } });

    render(<AccountEntriesPage />);

    expect(await screen.findByText("No entries yet")).toBeDefined();
  });

  test("shows an error state with a retry action when loading fails", async () => {
    listEntries.mockRejectedValueOnce(new Error("network"));

    render(<AccountEntriesPage />);

    expect(await screen.findByText("Couldn't load your entries")).toBeDefined();
  });

  test("lists real entries with their ticket numbers — never mock data", async () => {
    listEntries.mockResolvedValueOnce({ data: [entry], meta: { current_page: 1, last_page: 1, per_page: 10, total: 1 } });

    render(<AccountEntriesPage />);

    expect(await screen.findByText("BMW M4 Competition")).toBeDefined();
    expect(screen.getByText("#1, #2, #3")).toBeDefined();
    expect(screen.getByText(/Order RG-482915/)).toBeDefined();
  });

  // -- P9: read-only lifecycle status badge -----------------------------------------------------

  test("shows no status badge for a still-open (active) competition", async () => {
    listEntries.mockResolvedValueOnce({ data: [entry], meta: { current_page: 1, last_page: 1, per_page: 10, total: 1 } });

    render(<AccountEntriesPage />);

    await screen.findByText("BMW M4 Competition");
    expect(screen.queryByText("Drawn")).toBeNull();
    expect(screen.queryByText("Closed")).toBeNull();
  });

  test("shows a 'Drawn' badge once the competition has been drawn — never a winner claim", async () => {
    const drawnEntry = { ...entry, competition: { ...entry.competition, status: "drawn" } };
    listEntries.mockResolvedValueOnce({ data: [drawnEntry], meta: { current_page: 1, last_page: 1, per_page: 10, total: 1 } });

    render(<AccountEntriesPage />);

    expect(await screen.findByText("Drawn")).toBeDefined();
    expect(screen.queryByText(/won/i)).toBeNull();
    expect(screen.queryByText(/claim/i)).toBeNull();
  });
});
