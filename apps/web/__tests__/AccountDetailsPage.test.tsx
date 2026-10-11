import { afterEach, describe, expect, test, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import AccountDetailsPage from "@/app/account/details/page";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace: vi.fn(), push: vi.fn() }),
  usePathname: () => "/account/details",
}));

type AuthUser = { id: number; name: string; email: string; email_verified_at: string | null };
const authState: { status: "loading" | "guest" | "authenticated"; user: AuthUser | null } = {
  status: "authenticated",
  user: { id: 1, name: "Amara Okafor", email: "amara@example.com", email_verified_at: "2026-09-01T10:00:00Z" },
};
vi.mock("@/lib/auth/AuthContext", () => ({
  useAuth: () => ({ ...authState, logout: vi.fn() }),
}));

vi.mock("@/lib/auth/client", () => ({
  resendVerification: vi.fn(),
}));

describe("AccountDetailsPage", () => {
  afterEach(() => {
    authState.status = "authenticated";
    authState.user = { id: 1, name: "Amara Okafor", email: "amara@example.com", email_verified_at: "2026-09-01T10:00:00Z" };
  });

  test("shows name, email and a verified status for a verified account", () => {
    render(<AccountDetailsPage />);

    expect(screen.getByRole("heading", { level: 1, name: "Account details" })).toBeDefined();
    expect(screen.getByText("Amara Okafor")).toBeDefined();
    expect(screen.getByText("amara@example.com")).toBeDefined();
    expect(screen.getByText("Verified")).toBeDefined();
    expect(screen.queryByRole("button", { name: "Resend verification email" })).toBeNull();
  });

  test("an unverified account gets the verification notice and resend action", () => {
    authState.user = { ...authState.user!, email_verified_at: null };
    render(<AccountDetailsPage />);

    expect(screen.getByText("Not verified")).toBeDefined();
    expect(screen.getByRole("button", { name: "Resend verification email" })).toBeDefined();
  });

  test("never shows a password, token or internal id", () => {
    render(<AccountDetailsPage />);

    expect(document.body.textContent).not.toMatch(/password|token|sanctum|\bid\b/i);
  });

  test("a guest sees the loading redirect rather than details", () => {
    authState.status = "guest";
    authState.user = null;
    render(<AccountDetailsPage />);

    expect(screen.getByText("Loading…")).toBeDefined();
  });
});
