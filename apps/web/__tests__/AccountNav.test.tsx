import { afterEach, describe, expect, test, vi } from "vitest";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import AccountNav from "@/components/account/AccountNav";

const pathname = { current: "/account/entries" };
vi.mock("next/navigation", () => ({
  usePathname: () => pathname.current,
}));

describe("AccountNav", () => {
  afterEach(() => {
    pathname.current = "/account/entries";
  });

  test("marks the current section and links the real account routes", () => {
    render(<AccountNav onSignOut={vi.fn()} signingOut={false} />);

    const nav = screen.getByRole("navigation", { name: "Account" });
    const entries = within(nav).getAllByRole("link", { name: "My entries" })[0];
    expect(entries.getAttribute("aria-current")).toBe("page");
    expect(within(nav).getAllByRole("link", { name: "Overview" })[0].getAttribute("aria-current")).toBeNull();
    expect(within(nav).getAllByRole("link", { name: "Account details" })[0].getAttribute("href")).toBe(
      "/account/details",
    );
    expect(within(nav).getAllByRole("link", { name: "Responsible play" })[0].getAttribute("href")).toBe(
      "/responsible-play",
    );
  });

  test("the overview is active only on the exact overview route", () => {
    pathname.current = "/account";
    render(<AccountNav onSignOut={vi.fn()} signingOut={false} />);

    const nav = screen.getByRole("navigation", { name: "Account" });
    expect(within(nav).getAllByRole("link", { name: "Overview" })[0].getAttribute("aria-current")).toBe("page");
    expect(within(nav).getAllByRole("link", { name: "My entries" })[0].getAttribute("aria-current")).toBeNull();
  });

  test("sections the backend does not serve yet are shown as unavailable, never as links", () => {
    render(<AccountNav onSignOut={vi.fn()} signingOut={false} />);

    const nav = screen.getByRole("navigation", { name: "Account" });
    expect(within(nav).queryByRole("link", { name: "Addresses" })).toBeNull();
    expect(within(nav).queryByRole("link", { name: "Communication" })).toBeNull();
    expect(within(nav).getAllByText("Addresses")[0].closest("[aria-disabled='true']")).not.toBeNull();
  });

  test("sign out calls the handler", async () => {
    const onSignOut = vi.fn();
    const user = userEvent.setup();
    render(<AccountNav onSignOut={onSignOut} signingOut={false} />);

    await user.click(screen.getAllByRole("button", { name: "Sign out" })[0]);
    expect(onSignOut).toHaveBeenCalledTimes(1);
  });
});
