import { afterEach, describe, expect, test, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import RequireAuth from "@/components/auth/RequireAuth";

const replace = vi.fn();
let mockStatus: "loading" | "authenticated" | "guest" = "loading";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace }),
  usePathname: () => "/account",
}));

vi.mock("@/lib/auth/AuthContext", () => ({
  useAuth: () => ({ status: mockStatus }),
}));

describe("RequireAuth", () => {
  afterEach(() => {
    vi.clearAllMocks();
  });

  test("renders a loading state and does not redirect while auth is loading", () => {
    mockStatus = "loading";
    render(
      <RequireAuth>
        <p>Protected content</p>
      </RequireAuth>,
    );

    expect(screen.queryByText("Protected content")).toBeNull();
    expect(replace).not.toHaveBeenCalled();
  });

  test("redirects a guest to /login with a return URL", () => {
    mockStatus = "guest";
    render(
      <RequireAuth>
        <p>Protected content</p>
      </RequireAuth>,
    );

    expect(replace).toHaveBeenCalledWith("/login?redirect=%2Faccount");
    expect(screen.queryByText("Protected content")).toBeNull();
  });

  test("renders children for an authenticated user", () => {
    mockStatus = "authenticated";
    render(
      <RequireAuth>
        <p>Protected content</p>
      </RequireAuth>,
    );

    expect(screen.getByText("Protected content")).toBeDefined();
    expect(replace).not.toHaveBeenCalled();
  });
});
