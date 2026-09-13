import { afterEach, describe, expect, test, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import RegisterForm from "@/components/auth/RegisterForm";
import { ValidationError } from "@/lib/auth/client";

const push = vi.fn();

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push }),
}));

const register = vi.fn();

vi.mock("@/lib/auth/AuthContext", () => ({
  useAuth: () => ({ register }),
}));

describe("RegisterForm", () => {
  afterEach(() => {
    vi.clearAllMocks();
  });

  test("validates required fields and password confirmation client-side", async () => {
    const user = userEvent.setup();
    render(<RegisterForm />);

    await user.type(screen.getByLabelText("Name"), "Jane Doe");
    await user.type(screen.getByLabelText("Email"), "jane@example.com");
    await user.type(screen.getByLabelText("Password"), "password123");
    await user.type(screen.getByLabelText("Confirm password"), "different");
    await user.click(screen.getByRole("button", { name: "Create account" }));

    expect(await screen.findByText("Passwords do not match.")).toBeDefined();
    expect(register).not.toHaveBeenCalled();
  });

  test("submits and redirects to /account on success", async () => {
    register.mockResolvedValueOnce({ id: 1, name: "Jane Doe", email: "jane@example.com", email_verified_at: null });
    const user = userEvent.setup();
    render(<RegisterForm />);

    await user.type(screen.getByLabelText("Name"), "Jane Doe");
    await user.type(screen.getByLabelText("Email"), "jane@example.com");
    await user.type(screen.getByLabelText("Password"), "password123");
    await user.type(screen.getByLabelText("Confirm password"), "password123");
    await user.click(screen.getByRole("button", { name: "Create account" }));

    await waitFor(() => expect(register).toHaveBeenCalled());
    await waitFor(() => expect(push).toHaveBeenCalledWith("/account"));
  });

  test("shows server-side validation errors (e.g. duplicate email)", async () => {
    register.mockRejectedValueOnce(
      new ValidationError("failed", { email: ["The email has already been taken."] }),
    );
    const user = userEvent.setup();
    render(<RegisterForm />);

    await user.type(screen.getByLabelText("Name"), "Jane Doe");
    await user.type(screen.getByLabelText("Email"), "jane@example.com");
    await user.type(screen.getByLabelText("Password"), "password123");
    await user.type(screen.getByLabelText("Confirm password"), "password123");
    await user.click(screen.getByRole("button", { name: "Create account" }));

    expect(await screen.findByText("The email has already been taken.")).toBeDefined();
  });
});
