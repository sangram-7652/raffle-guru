import { afterEach, describe, expect, test, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import LoginForm from "@/components/auth/LoginForm";
import { AuthError, ValidationError } from "@/lib/auth/client";

const push = vi.fn();

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push }),
  useSearchParams: () => new URLSearchParams(),
}));

const login = vi.fn();

vi.mock("@/lib/auth/AuthContext", () => ({
  useAuth: () => ({ login }),
}));

describe("LoginForm", () => {
  afterEach(() => {
    vi.clearAllMocks();
  });

  test("shows validation errors instead of submitting when fields are empty", async () => {
    const user = userEvent.setup();
    render(<LoginForm />);

    await user.click(screen.getByRole("button", { name: "Sign in" }));

    expect(await screen.findByText("Enter your email address.")).toBeDefined();
    expect(screen.getByText("Enter your password.")).toBeDefined();
    expect(login).not.toHaveBeenCalled();
  });

  test("submits and redirects on success", async () => {
    login.mockResolvedValueOnce({ id: 1, name: "Jane", email: "jane@example.com", email_verified_at: null });
    const user = userEvent.setup();
    render(<LoginForm />);

    await user.type(screen.getByLabelText("Email"), "jane@example.com");
    await user.type(screen.getByLabelText("Password"), "password123");
    await user.click(screen.getByRole("button", { name: "Sign in" }));

    await waitFor(() => expect(login).toHaveBeenCalledWith({
      email: "jane@example.com",
      password: "password123",
    }));
    await waitFor(() => expect(push).toHaveBeenCalledWith("/account"));
  });

  test("shows a generic message on invalid credentials", async () => {
    login.mockRejectedValueOnce(
      new ValidationError("failed", { email: ["These credentials do not match our records."] }),
    );
    const user = userEvent.setup();
    render(<LoginForm />);

    await user.type(screen.getByLabelText("Email"), "jane@example.com");
    await user.type(screen.getByLabelText("Password"), "wrong");
    await user.click(screen.getByRole("button", { name: "Sign in" }));

    expect(await screen.findByRole("alert")).toHaveTextContent(
      "These credentials do not match our records.",
    );
  });

  test("shows a server-error message when the request fails outright", async () => {
    login.mockRejectedValueOnce(new AuthError("Server error", 500));
    const user = userEvent.setup();
    render(<LoginForm />);

    await user.type(screen.getByLabelText("Email"), "jane@example.com");
    await user.type(screen.getByLabelText("Password"), "password123");
    await user.click(screen.getByRole("button", { name: "Sign in" }));

    expect(await screen.findByRole("alert")).toHaveTextContent("Server error");
  });
});
