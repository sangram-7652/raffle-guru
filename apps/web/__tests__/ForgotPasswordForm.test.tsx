import { afterEach, describe, expect, test, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import ForgotPasswordForm from "@/components/auth/ForgotPasswordForm";

const forgotPassword = vi.fn();

vi.mock("@/lib/auth/client", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@/lib/auth/client")>();
  return { ...actual, forgotPassword: (...args: unknown[]) => forgotPassword(...args) };
});

describe("ForgotPasswordForm", () => {
  afterEach(() => {
    vi.clearAllMocks();
  });

  test("validates the email field", async () => {
    const user = userEvent.setup();
    render(<ForgotPasswordForm />);

    await user.click(screen.getByRole("button", { name: "Send reset link" }));

    expect(await screen.findByText("Enter your email address.")).toBeDefined();
    expect(forgotPassword).not.toHaveBeenCalled();
  });

  test("shows the same generic success message regardless of whether the email exists", async () => {
    forgotPassword.mockResolvedValueOnce({ message: "generic" });
    const user = userEvent.setup();
    render(<ForgotPasswordForm />);

    await user.type(screen.getByLabelText("Email"), "anyone@example.com");
    await user.click(screen.getByRole("button", { name: "Send reset link" }));

    expect(
      await screen.findByText(/we've sent a link to reset the password/i),
    ).toBeDefined();
  });
});
