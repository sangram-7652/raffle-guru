import { describe, expect, test } from "vitest";
import { render, screen } from "@testing-library/react";
import ImageFrame from "@/components/media/ImageFrame";

describe("ImageFrame", () => {
  test("an empty src renders a neutral labelled placeholder instead of breaking next/image", () => {
    render(<ImageFrame src="" alt="BMW M4 Competition" ratio="4/3" />);

    const placeholder = screen.getByRole("img", { name: "BMW M4 Competition" });
    expect(placeholder).toBeDefined();
    expect(placeholder.querySelector("img")).toBeNull();
  });

  test("a real src still renders an image", () => {
    render(<ImageFrame src="https://images.unsplash.com/photo-1" alt="Prize" ratio="4/3" />);

    expect(screen.getByRole("img", { name: "Prize" }).tagName).toBe("IMG");
  });
});
