import { describe, expect, test } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import CompetitionInfo from "@/components/competition/CompetitionInfo";
import type { Competition } from "@/data/competitions";

function competition(prizes: Competition["prizes"]): Competition {
  return {
    id: "1",
    slug: "bmw-m4",
    title: "BMW M4 Competition",
    blurb: "",
    image: "",
    badge: "Live",
    category: "Cars",
    entryPrice: 10,
    soldPct: 0,
    drawLabel: "Fri 8PM",
    prize: prizes[0]?.title ?? "",
    prizeValue: 0,
    prizes,
    images: [],
    totalEntries: 100,
    maxEntriesPerUser: null,
    drawDate: "2999-10-12T19:00:00Z",
    status: "live",
    featured: false,
    description: ["About it."],
    prizeDetails: [],
    rules: [],
    question: { prompt: "", options: [], answerIndex: -1 },
    faqs: [],
  } as Competition;
}

describe("CompetitionInfo prize details", () => {
  test("lists every prize with its title and quantity when there is more than one", async () => {
    const user = userEvent.setup();
    render(
      <CompetitionInfo
        competition={competition([
          { id: 1, title: "BMW M4", description: ["Insured for a year"], value: 60000, quantity: 1 },
          { id: 2, title: "Rolex Datejust", description: ["Certificate included"], value: 9000, quantity: 2 },
        ])}
      />,
    );

    await user.click(screen.getByRole("button", { name: "Prize details" }));

    expect(screen.getByText("BMW M4")).toBeDefined();
    expect(screen.getByText("Insured for a year")).toBeDefined();
    expect(screen.getByText("Rolex Datejust × 2")).toBeDefined();
    expect(screen.getByText("Certificate included")).toBeDefined();
  });

  test("a single prize without description falls back to its title, with no heading repeat", async () => {
    const user = userEvent.setup();
    render(<CompetitionInfo competition={competition([{ id: 1, title: "Cash", description: [], value: 500, quantity: 1 }])} />);

    await user.click(screen.getByRole("button", { name: "Prize details" }));

    expect(screen.getAllByText("Cash")).toHaveLength(1);
  });
});
