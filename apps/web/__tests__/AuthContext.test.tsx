import { describe, expect, test, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { AuthProvider, useAuth } from "@/lib/auth/AuthContext";

const getCurrentUser = vi.fn();

vi.mock("@/lib/auth/client", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@/lib/auth/client")>();
  return { ...actual, getCurrentUser: () => getCurrentUser() };
});

function Probe() {
  const { status, user } = useAuth();
  return <p>{status === "authenticated" ? `in:${user?.email}` : status}</p>;
}

describe("AuthProvider", () => {
  test("starts in the loading state, then resolves to authenticated", async () => {
    getCurrentUser.mockResolvedValueOnce({
      data: { id: 1, name: "Jane", email: "jane@example.com", email_verified_at: null },
    });

    render(
      <AuthProvider>
        <Probe />
      </AuthProvider>,
    );

    expect(screen.getByText("loading")).toBeDefined();
    expect(await screen.findByText("in:jane@example.com")).toBeDefined();
  });

  test("resolves to guest when the session check fails (unauthenticated)", async () => {
    getCurrentUser.mockRejectedValueOnce(new Error("401"));

    render(
      <AuthProvider>
        <Probe />
      </AuthProvider>,
    );

    expect(await screen.findByText("guest")).toBeDefined();
  });
});
