import { expect, test } from "vitest";
import { render, screen } from "@testing-library/react";
import Button from "@/components/ui/Button";

test("Button renders its children as a real button by default", () => {
  render(<Button>Enter now</Button>);
  expect(screen.getByRole("button", { name: "Enter now" })).toBeDefined();
});

test("Button renders as a link when href is provided", () => {
  render(<Button href="/competitions">Browse</Button>);
  expect(screen.getByRole("link", { name: "Browse" })).toBeDefined();
});
